<?php

namespace App\Http\Controllers\Work;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Setting;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Check in / out, the daily register and monthly timesheets. */
class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $svc) {}

    /** My day: today's record and the rules shown on the check-in card. */
    public function me(Request $request): JsonResponse
    {
        $s = $this->svc->settings();

        return response()->json([
            'today' => $this->svc->today($request->user())?->toApiArray(),
            'late_after' => $s['late_after'],
            'office_set' => $s['office_lat'] !== null,
            'require_location' => (bool) $s['require_location'],
            'can_manage' => $request->user()->hasPermission('attendance.edit'),
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $d = $request->validate($this->fix() + [
            'mode' => ['nullable', Rule::in(array_keys(Attendance::MODES))],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return response()->json($this->svc->checkIn($request->user(), $d)->toApiArray(), 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $d = $request->validate($this->fix() + ['note' => ['nullable', 'string', 'max:300']]);

        return response()->json($this->svc->checkOut($request->user(), $d)->toApiArray());
    }

    /** Everyone on one day (attendance.edit). */
    public function register(Request $request): JsonResponse
    {
        $d = $request->validate(['date' => ['nullable', 'date']]);
        $day = CarbonImmutable::parse($d['date'] ?? today());
        $rows = $this->svc->register($day);
        $count = fn ($s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return response()->json([
            'date' => $day->toDateString(),
            'rows' => $rows,
            'summary' => ['present' => $count('present') + $count('late'), 'late' => $count('late'), 'leave' => $count('leave'), 'absent' => $count('absent'), 'people' => count($rows)],
        ]);
    }

    /** A month for one person: yourself, or anyone with attendance.edit. */
    public function timesheet(Request $request): JsonResponse
    {
        $d = $request->validate([
            'user_id' => ['nullable', 'integer'], 'year' => ['required', 'integer', 'min:2020', 'max:2100'], 'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json($this->svc->timesheet($this->person($request, $d['user_id'] ?? null), (int) $d['year'], (int) $d['month']));
    }

    /** Monthly summary for everyone as CSV (attendance.export). */
    public function export(Request $request): StreamedResponse
    {
        $d = $request->validate(['year' => ['required', 'integer'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $sheets = $this->svc->people()->map(fn (User $u) => $this->svc->timesheet($u, (int) $d['year'], (int) $d['month']));
        $name = sprintf('attendance-%04d-%02d.csv', $d['year'], $d['month']);

        return response()->streamDownload(function () use ($sheets) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Date', 'Status', 'Check in', 'Check out', 'Hours', 'Mode', 'Late', 'Note', 'Tasks done']);
            foreach ($sheets as $s) {
                foreach ($s['days'] as $day) {
                    $a = $day['attendance'];
                    fputcsv($out, [
                        $s['user']['name'], $day['date'], $day['leave'] ? 'Leave ('.$day['leave'].')' : ($day['off'] ?? $day['status']),
                        $a && $a['check_in_at'] ? CarbonImmutable::parse($a['check_in_at'])->tz(config('app.timezone'))->format('H:i') : '',
                        $a && $a['check_out_at'] ? CarbonImmutable::parse($a['check_out_at'])->tz(config('app.timezone'))->format('H:i') : ($a && $a['auto_closed'] ? 'not checked out' : ''),
                        $a && $a['minutes'] !== null ? round($a['minutes'] / 60, 2) : '',
                        $a ? Attendance::MODES[$a['mode']] ?? $a['mode'] : '', $a && $a['late'] ? 'Yes' : '', $a['note'] ?? '', $day['tasks_done'] ?: '',
                    ]);
                }
                fputcsv($out, [$s['user']['name'], 'TOTAL', 'Present '.$s['totals']['present'].', late '.$s['totals']['late'].', half '.$s['totals']['half'].', leave '.$s['totals']['leave'].', absent '.$s['totals']['absent'],
                    '', '', round($s['totals']['minutes'] / 60, 2), '', '', '', $s['totals']['tasks']]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Correct or add a day (forgot to check in/out, field day…). */
    public function save(Request $request): JsonResponse
    {
        $d = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'check_in' => ['required', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'],
            'mode' => ['required', Rule::in(array_keys(Attendance::MODES))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $date = CarbonImmutable::parse($d['date'])->toDateString();
        $in = CarbonImmutable::parse("$date {$d['check_in']}");
        $out = isset($d['check_out']) ? CarbonImmutable::parse("$date {$d['check_out']}") : null;
        $lateAt = CarbonImmutable::parse($date.' '.$this->svc->settings()['late_after']);

        $a = Attendance::where('user_id', $d['user_id'])->whereDate('date', $date)->first() ?? new Attendance(['user_id' => $d['user_id'], 'date' => $date]);
        $a->fill([
            'check_in_at' => $in, 'check_out_at' => $out, 'mode' => $d['mode'], 'late' => $in->gt($lateAt),
            'worked_minutes' => $out ? (int) $in->diffInMinutes($out) : null, 'auto_closed' => false,
            'note' => $d['note'] ?? $a->note, 'edited_by' => $request->user()->id,
        ])->save();

        return response()->json($a->fresh()->toApiArray());
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->svc->settings());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'office_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:office_lng'],
            'office_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:office_lat'],
            'radius_m' => ['required', 'integer', 'min:30', 'max:5000'],
            'late_after' => ['required', 'date_format:H:i'],
            'half_day_hours' => ['required', 'numeric', 'min:1', 'max:12'],
            'require_location' => ['required', 'boolean'],
        ]);
        Setting::put('attendance', $d);

        return response()->json($this->svc->settings());
    }

    private function fix(): array
    {
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    private function person(Request $request, ?int $id): User
    {
        $me = $request->user();
        if (! $id || $id === $me->id) {
            return $me;
        }
        abort_unless($me->hasPermission('attendance.edit'), 403, 'You can only see your own timesheet.');

        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->findOrFail($id);
    }
}

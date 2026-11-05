<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Check in / check out with the phone's location.
 * - Within the office radius → "office"; otherwise the person picks field visit or WFH.
 * - Checking in after the admin's "late after" time on a working day marks it late.
 */
class AttendanceService
{
    public const DEFAULTS = [
        'office_lat' => null, 'office_lng' => null, 'radius_m' => 200,
        'late_after' => '10:00', 'half_day_hours' => 4, 'require_location' => true,
    ];

    public function __construct(private LeaveService $leave) {}

    public function settings(): array
    {
        return array_merge(self::DEFAULTS, (array) Setting::get('attendance', []));
    }

    public function today(User $user): ?Attendance
    {
        return Attendance::where('user_id', $user->id)->whereDate('date', today())->first();
    }

    public function checkIn(User $user, array $d): Attendance
    {
        if ($this->today($user)) {
            throw ValidationException::withMessages(['check_in' => 'You have already checked in today.']);
        }
        $s = $this->settings();
        $hasFix = isset($d['lat'], $d['lng']);
        if (! $hasFix && $s['require_location'] && ($d['mode'] ?? 'office') !== 'wfh') {
            throw ValidationException::withMessages(['location' => 'Allow location access to check in (or choose Work from home).']);
        }

        $distance = $hasFix && $s['office_lat'] !== null ? (int) round($this->metres($d['lat'], $d['lng'], $s['office_lat'], $s['office_lng'])) : null;
        $atOffice = $distance !== null && $distance <= $s['radius_m'] + min((int) ($d['accuracy'] ?? 0), 100);
        $mode = $d['mode'] ?? null;
        if (($mode === 'office' || $mode === null) && $distance !== null && ! $atOffice) {
            throw ValidationException::withMessages(['mode' => sprintf('You are %s from the office. Choose Field visit or Work from home.', $this->km($distance))]);
        }
        $mode ??= 'office';

        $now = now();
        $lateAt = CarbonImmutable::parse(today()->toDateString().' '.$s['late_after']);
        $working = $this->leave->workingDays(CarbonImmutable::today(), CarbonImmutable::today()) > 0;

        return Attendance::create([
            'user_id' => $user->id, 'date' => today(), 'check_in_at' => $now, 'mode' => $mode,
            'late' => $working && $now->gt($lateAt),
            'in_lat' => $d['lat'] ?? null, 'in_lng' => $d['lng'] ?? null, 'in_accuracy' => isset($d['accuracy']) ? (int) $d['accuracy'] : null,
            'in_distance' => $distance, 'note' => $d['note'] ?? null,
        ]);
    }

    public function checkOut(User $user, array $d): Attendance
    {
        $a = $this->today($user);
        if (! $a) {
            throw ValidationException::withMessages(['check_out' => 'Check in first.']);
        }
        if ($a->check_out_at) {
            throw ValidationException::withMessages(['check_out' => 'You have already checked out today.']);
        }
        $a->update([
            'check_out_at' => now(), 'out_lat' => $d['lat'] ?? null, 'out_lng' => $d['lng'] ?? null,
            'worked_minutes' => (int) $a->check_in_at->diffInMinutes(now()),
            'note' => trim(($a->note ? $a->note.' · ' : '').($d['note'] ?? '')) ?: null,
        ]);

        return $a;
    }

    /** End of day: anyone still checked in is closed without hours (shown as "forgot to check out"). */
    public function closeOpenDays(): int
    {
        return Attendance::whereNull('check_out_at')->whereNotNull('check_in_at')->whereDate('date', '<', today())
            ->update(['auto_closed' => true]);
    }

    /** Staff who take attendance. */
    public function people(): Collection
    {
        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')->get(['id', 'name', 'role']);
    }

    /** Everyone's status on one day: present / late / on leave / absent / holiday / weekly off. */
    public function register(CarbonImmutable $day): array
    {
        $people = $this->people();
        $marks = Attendance::whereDate('date', $day)->get()->keyBy('user_id');
        $off = $this->dayOff($day);
        $onLeave = $this->leaveOn($day, $day);

        return $people->map(function (User $u) use ($marks, $off, $onLeave, $day) {
            $a = $marks->get($u->id);
            $leave = $onLeave[$u->id][$day->toDateString()] ?? null;
            $status = $a ? ($a->late ? 'late' : 'present') : ($leave ? 'leave' : ($off ?: ($day->isFuture() ? 'upcoming' : 'absent')));

            return ['user' => ['id' => $u->id, 'name' => $u->name], 'status' => $status, 'leave' => $leave, 'attendance' => $a?->toApiArray()];
        })->values()->all();
    }

    /** One person's month: every day with in/out, hours and tasks done. */
    public function timesheet(User $user, int $year, int $month): array
    {
        $from = CarbonImmutable::create($year, $month, 1);
        $to = $from->endOfMonth();
        $marks = Attendance::where('user_id', $user->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->keyBy(fn ($a) => $a->date->toDateString());
        $leave = $this->leaveOn($from, $to)[$user->id] ?? [];
        $done = Task::where('assigned_to', $user->id)->where('status', 'done')->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['completed_at', 'minutes_spent'])->groupBy(fn ($t) => $t->completed_at->toDateString());
        $s = $this->settings();
        // Days before someone's first ever check-in are not "absent" (attendance had not started for them).
        $first = Attendance::where('user_id', $user->id)->min('date');
        $startedOn = $first ? CarbonImmutable::parse($first)->toDateString() : null;

        $days = [];
        $tot = ['present' => 0, 'late' => 0, 'half' => 0, 'leave' => 0, 'absent' => 0, 'minutes' => 0, 'tasks' => 0, 'working_days' => 0];
        foreach (CarbonPeriod::create($from, $to) as $d) {
            $d = CarbonImmutable::parse($d);
            $key = $d->toDateString();
            $a = $marks->get($key);
            $off = $this->dayOff($d);
            if (! $off) {
                $tot['working_days']++;
            }
            $min = $a?->minutes();
            $half = $a && $min !== null && $a->check_out_at && $min < $s['half_day_hours'] * 60;
            $status = $a ? ($half ? 'half' : ($a->late ? 'late' : 'present')) : (isset($leave[$key]) ? 'leave' : ($off ?: ($d->gt(today()) ? 'upcoming'
                : (! $startedOn || $key < $startedOn ? 'not_started' : 'absent'))));
            match ($status) {
                'present' => $tot['present']++,
                'late' => [$tot['present']++, $tot['late']++],
                'half' => $tot['half']++,
                'leave' => $tot['leave']++,
                'absent' => $tot['absent']++,
                default => null,
            };
            $tot['minutes'] += $min ?? 0;
            $tasks = $done->get($key, collect());
            $tot['tasks'] += $tasks->count();
            $days[] = [
                'date' => $key, 'status' => $status, 'off' => $off, 'leave' => $leave[$key] ?? null,
                'attendance' => $a?->toApiArray(), 'tasks_done' => $tasks->count(), 'task_minutes' => (int) $tasks->sum('minutes_spent'),
            ];
        }

        return ['user' => ['id' => $user->id, 'name' => $user->name], 'days' => $days, 'totals' => $tot];
    }

    /** 'holiday: Diwali', 'weekly_off', or null for a working day. */
    private function dayOff(CarbonImmutable $d): ?string
    {
        if (in_array($d->dayOfWeek, config('crm.weekly_offs', [0]), true)) {
            return 'weekly_off';
        }
        $h = Holiday::whereDate('date', $d)->where('optional', false)->first();

        return $h ? 'holiday: '.$h->name : null;
    }

    /** [user_id][Y-m-d] => leave type code, for approved leave in the range. */
    private function leaveOn(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        LeaveRequest::with(['employee:id,user_id', 'leaveType:id,code'])->where('status', 'approved')
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->get()
            ->each(function (LeaveRequest $r) use (&$out, $from, $to) {
                $uid = $r->employee?->user_id;
                if (! $uid) {
                    return;
                }
                $start = max($r->from_date->toDateString(), $from->toDateString());
                $end = min($r->to_date->toDateString(), $to->toDateString());
                foreach (CarbonPeriod::create($start, $end) as $d) {
                    $out[$uid][$d->toDateString()] = $r->leaveType?->code ?? 'Leave';
                }
            });

        return $out;
    }

    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private function km(int $m): string
    {
        return $m >= 1000 ? round($m / 1000, 1).' km' : $m.' m';
    }
}

<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

/**
 * Leave rules:
 * - Days are counted Monday–Saturday (weekly offs from config) and skip public holidays.
 * - Paid leave (CL, SL) is limited by the yearly quota; Loss of Pay has no limit.
 * - Leave years run January–December.
 */
class LeaveService
{
    /** Working days between two dates (inclusive); a half day counts 0.5. */
    public function workingDays(CarbonImmutable $from, CarbonImmutable $to, bool $halfDay = false): float
    {
        $holidays = Holiday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('optional', false)->pluck('date')->map(fn ($d) => $d->toDateString())->all();
        $offs = config('crm.weekly_offs', [0]);

        $days = 0;
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if (! in_array($day->dayOfWeek, $offs, true) && ! in_array($day->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return $halfDay && $days > 0 ? 0.5 : (float) $days;
    }

    /** Working days of a leave request that fall inside one month (for payslips). */
    public function daysInMonth(LeaveRequest $r, int $year, int $month): float
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $from = max($start, CarbonImmutable::parse($r->from_date));
        $to = min($start->endOfMonth()->startOfDay(), CarbonImmutable::parse($r->to_date));
        if ($from > $to) {
            return 0;
        }

        return $this->workingDays($from, $to, $r->half_day);
    }

    /** @return list<array{type: array, allotted: float|null, used: float, pending: float, remaining: float|null}> */
    public function balances(Employee $employee, int $year): array
    {
        $requests = $employee->leaveRequests()
            ->whereIn('status', ['pending', 'approved'])
            ->whereYear('from_date', $year)
            ->get();

        return LeaveType::where('active', true)->orderBy('id')->get()->map(function (LeaveType $t) use ($requests) {
            $mine = $requests->where('leave_type_id', $t->id);
            $used = (float) $mine->where('status', 'approved')->sum('days');
            $pending = (float) $mine->where('status', 'pending')->sum('days');
            $allotted = $t->paid ? $t->yearly_quota : null;

            return [
                'type' => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'paid' => $t->paid],
                'allotted' => $allotted,
                'used' => $used,
                'pending' => $pending,
                'remaining' => $allotted === null ? null : max(0, $allotted - $used - $pending),
            ];
        })->values()->all();
    }

    public function request(Employee $employee, array $data): LeaveRequest
    {
        $type = LeaveType::where('active', true)->findOrFail($data['leave_type_id']);
        $from = CarbonImmutable::parse($data['from_date'])->startOfDay();
        $to = CarbonImmutable::parse($data['to_date'])->startOfDay();
        $halfDay = (bool) ($data['half_day'] ?? false);

        if ($halfDay && ! $from->equalTo($to)) {
            throw ValidationException::withMessages(['half_day' => 'A half day must start and end on the same date.']);
        }
        if ($from->year !== $to->year) {
            throw ValidationException::withMessages(['to_date' => 'Please split leave that crosses into the next year into two requests.']);
        }
        if ($from < CarbonImmutable::today()->subDays(config('crm.leave_backdate_days'))) {
            throw ValidationException::withMessages(['from_date' => 'Leave can be back-dated by up to '.config('crm.leave_backdate_days').' days only.']);
        }

        $days = $this->workingDays($from, $to, $halfDay);
        if ($days <= 0) {
            throw ValidationException::withMessages(['from_date' => 'Those dates are all weekly offs or public holidays.']);
        }

        $overlap = $employee->leaveRequests()->whereIn('status', ['pending', 'approved'])
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['from_date' => 'You already have leave booked on some of these dates.']);
        }

        if ($type->paid) {
            $balance = collect($this->balances($employee, $from->year))->firstWhere('type.id', $type->id);
            if ($days > ($balance['remaining'] ?? 0)) {
                throw ValidationException::withMessages([
                    'leave_type_id' => "Only {$balance['remaining']} day(s) of {$type->name} left this year. Choose Loss of pay for the rest.",
                ]);
            }
        }

        return $employee->leaveRequests()->create([
            'leave_type_id' => $type->id,
            'from_date' => $from,
            'to_date' => $to,
            'half_day' => $halfDay,
            'days' => $days,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Builds monthly payslips from each employee's salary structure.
 * Loss of pay = (gross ÷ days in month) × unpaid leave days, taken off earnings
 * proportionally. Published payslips are never overwritten.
 */
class PayslipGenerator
{
    public function __construct(private LeaveService $leave) {}

    /** @return array{generated: int, skipped: list<array{employee: string, reason: string}>} */
    public function generate(int $year, int $month, ?User $by = null): array
    {
        $monthStart = CarbonImmutable::create($year, $month, 1);
        $monthEnd = $monthStart->endOfMonth();
        $generated = 0;
        $skipped = [];

        $employees = Employee::where('status', 'active')
            ->where(fn ($q) => $q->whereNull('doj')->orWhereDate('doj', '<=', $monthEnd))
            ->orderBy('emp_code')->get();

        foreach ($employees as $employee) {
            $existing = Payslip::where(['employee_id' => $employee->id, 'year' => $year, 'month' => $month])->first();
            if ($existing?->status === 'published') {
                $skipped[] = ['employee' => $employee->name, 'reason' => 'Already published'];
                continue;
            }

            $salary = $employee->salaryOn($monthEnd);
            if (! $salary) {
                // Often the salary was saved to start next month: say so, so it can be moved back.
                $later = $employee->salaryStructures()->reorder('effective_from')->first();
                $skipped[] = $later
                    ? ['employee' => $employee->name, 'reason' => 'Salary starts '.$later->effective_from->format('j M Y'),
                        'employee_id' => $employee->id, 'salary_id' => $later->id, 'starts' => $later->effective_from->toDateString()]
                    : ['employee' => $employee->name, 'reason' => 'No salary details', 'employee_id' => $employee->id];
                continue;
            }

            Payslip::updateOrCreate(
                ['employee_id' => $employee->id, 'year' => $year, 'month' => $month],
                [...$this->calculate($employee, $salary, $monthStart), 'status' => 'draft', 'generated_by' => $by?->id],
            );
            $generated++;
        }

        return ['generated' => $generated, 'skipped' => $skipped, 'employees' => $employees->count()];
    }

    private function calculate(Employee $employee, SalaryStructure $salary, CarbonImmutable $month): array
    {
        $daysInMonth = $month->daysInMonth;

        $lopDays = LeaveRequest::query()
            ->where('employee_id', $employee->id)->where('status', 'approved')
            ->whereHas('leaveType', fn ($q) => $q->where('paid', false))
            ->whereDate('from_date', '<=', $month->endOfMonth())->whereDate('to_date', '>=', $month)
            ->get()
            ->sum(fn (LeaveRequest $r) => $this->leave->daysInMonth($r, $month->year, $month->month));

        // Someone who joined mid-month is paid from their joining date.
        $notJoinedDays = 0;
        if ($employee->doj && $employee->doj->greaterThan($month)) {
            $notJoinedDays = $employee->doj->day - 1;
        }

        $unpaid = min($daysInMonth, $lopDays + $notJoinedDays);
        $factor = ($daysInMonth - $unpaid) / $daysInMonth;

        $earnings = [];
        foreach (SalaryStructure::EARNINGS as $key => $label) {
            if ($salary->{$key} > 0) {
                $earnings[$label] = round($salary->{$key} * $factor, 2);
            }
        }
        $deductions = [];
        foreach (SalaryStructure::DEDUCTIONS as $key => $label) {
            if ($salary->{$key} > 0) {
                $deductions[$label] = round($salary->{$key}, 2);
            }
        }

        $gross = round(array_sum($earnings), 2);
        $totalDeductions = round(array_sum($deductions), 2);

        return [
            'days_in_month' => $daysInMonth,
            'lop_days' => $unpaid,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'gross' => $gross,
            'total_deductions' => $totalDeductions,
            'net' => round(max(0, $gross - $totalDeductions), 2),
        ];
    }
}

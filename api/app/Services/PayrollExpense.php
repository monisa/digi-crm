<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payslip;
use Carbon\CarbonImmutable;

/** Keeps one "Salaries" expense per month in step with the published payslips. */
class PayrollExpense
{
    public function sync(int $year, int $month, ?int $by = null): ?Expense
    {
        $slips = Payslip::where(compact('year', 'month'))->where('status', 'published')->get();
        $source = sprintf('payroll:%04d-%02d', $year, $month);
        if ($slips->isEmpty()) {
            Expense::where('source', $source)->delete();

            return null;
        }

        $label = CarbonImmutable::create($year, $month, 1)->format('F Y');

        return Expense::updateOrCreate(['source' => $source], [
            'spent_on' => CarbonImmutable::create($year, $month, 1)->endOfMonth()->toDateString(),
            'category' => 'Salaries',
            'amount' => round($slips->sum('gross'), 2), // cost to the company before employee deductions
            'gst' => 0,
            'payment_mode' => 'bank_transfer',
            'vendor' => 'Payroll',
            'notes' => "Salaries for $label: {$slips->count()} payslip(s). Net paid ".number_format($slips->sum('net'), 2).'; deductions (PF, PT, TDS…) '.number_format($slips->sum('total_deductions'), 2).'.',
            'status' => 'approved',
            'approved_at' => now(),
            'created_by' => $by,
        ]);
    }
}

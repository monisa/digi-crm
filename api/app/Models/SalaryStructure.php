<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryStructure extends Model
{
    protected $guarded = ['id'];

    public const EARNINGS = ['basic' => 'Basic', 'hra' => 'House Rent Allowance', 'special_allowance' => 'Special Allowance', 'other_allowance' => 'Other Allowance'];

    public const DEDUCTIONS = ['pf' => 'Provident Fund', 'esi' => 'ESI', 'professional_tax' => 'Professional Tax', 'tds' => 'Income Tax (TDS)', 'other_deduction' => 'Other Deductions'];

    protected function casts(): array
    {
        $money = array_fill_keys([...array_keys(self::EARNINGS), ...array_keys(self::DEDUCTIONS)], 'float');

        return ['effective_from' => 'date', ...$money];
    }

    public function gross(): float
    {
        return round(array_sum(array_map(fn ($k) => $this->{$k}, array_keys(self::EARNINGS))), 2);
    }

    public function deductions(): float
    {
        return round(array_sum(array_map(fn ($k) => $this->{$k}, array_keys(self::DEDUCTIONS))), 2);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'effective_from' => $this->effective_from->toDateString(),
            ...array_map(fn ($k) => $this->{$k}, array_combine(array_keys(self::EARNINGS), array_keys(self::EARNINGS))),
            ...array_map(fn ($k) => $this->{$k}, array_combine(array_keys(self::DEDUCTIONS), array_keys(self::DEDUCTIONS))),
            'bank_account_last4' => $this->bank_account_last4,
            'gross' => $this->gross(),
            'total_deductions' => $this->deductions(),
            'net' => round($this->gross() - $this->deductions(), 2),
            'ctc_yearly' => round($this->gross() * 12, 2),
        ];
    }
}

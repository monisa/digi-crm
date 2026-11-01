<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'earnings' => 'array', 'deductions' => 'array', 'gross' => 'float', 'total_deductions' => 'float',
            'net' => 'float', 'lop_days' => 'float', 'published_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function periodLabel(): string
    {
        return date('F Y', mktime(0, 0, 0, $this->month, 1, $this->year));
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'employee' => ['id' => $this->employee_id, 'name' => $this->employee?->name, 'emp_code' => $this->employee?->emp_code],
            'year' => $this->year,
            'month' => $this->month,
            'label' => $this->periodLabel(),
            'days_in_month' => $this->days_in_month,
            'lop_days' => $this->lop_days,
            'earnings' => $this->earnings,
            'deductions' => $this->deductions,
            'gross' => $this->gross,
            'total_deductions' => $this->total_deductions,
            'net' => $this->net,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}

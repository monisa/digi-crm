<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dob' => 'date', 'doj' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function salaryStructures(): HasMany
    {
        return $this->hasMany(SalaryStructure::class)->orderByDesc('effective_from');
    }

    /** Structure in force on the given date (defaults to today). */
    public function salaryOn(?\DateTimeInterface $date = null): ?SalaryStructure
    {
        return $this->salaryStructures()->whereDate('effective_from', '<=', $date ?? now())->first();
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class)->orderByDesc('year')->orderByDesc('month');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'emp_code' => $this->emp_code,
            'name' => $this->name,
            'office_email' => $this->office_email,
            'mobile' => $this->mobile,
            'designation' => $this->designation,
            'department' => $this->department,
            'dob' => $this->dob?->toDateString(),
            'doj' => $this->doj?->toDateString(),
            'address' => $this->address,
            'status' => $this->status,
            'reporting_manager' => $this->manager ? ['id' => $this->manager->id, 'name' => $this->manager->name] : null,
            'has_login' => (bool) $this->user_id,
        ];
    }
}

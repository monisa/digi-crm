<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'days' => 'float', 'half_day' => 'boolean', 'actioned_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'employee' => ['id' => $this->employee_id, 'name' => $this->employee?->name, 'designation' => $this->employee?->designation],
            'type' => ['id' => $this->leave_type_id, 'code' => $this->leaveType?->code, 'name' => $this->leaveType?->name],
            'from' => $this->from_date->toDateString(),
            'to' => $this->to_date->toDateString(),
            'half_day' => $this->half_day,
            'days' => $this->days,
            'reason' => $this->reason,
            'status' => $this->status,
            'approver' => $this->approver?->name,
            'approver_comment' => $this->approver_comment,
            'actioned_at' => $this->actioned_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}

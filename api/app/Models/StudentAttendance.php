<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendance extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'status' => $this->status,
            'note' => $this->note,
            'student' => $this->student ? ['id' => $this->student->id, 'name' => $this->student->name] : null,
            'reviewer' => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null,
            'review_comment' => $this->review_comment,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'whatsapp' => $this->wa_status,
            'whatsapp_error' => $this->wa_error,
            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

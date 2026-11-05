<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const MODES = ['office' => 'Office', 'field' => 'Field visit', 'wfh' => 'Work from home'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'late' => 'boolean', 'auto_closed' => 'boolean',
            'in_lat' => 'float', 'in_lng' => 'float', 'out_lat' => 'float', 'out_lng' => 'float',
        ];
    }

    /** Keep the date as a plain day (SQLite would otherwise store a time). */
    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = \Illuminate\Support\Carbon::parse($value)->toDateString();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function minutes(): ?int
    {
        if ($this->worked_minutes !== null) {
            return $this->worked_minutes;
        }

        return $this->check_in_at && ! $this->check_out_at && $this->date->isToday()
            ? (int) $this->check_in_at->diffInMinutes(now()) : null; // still at work
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'user' => $this->relationLoaded('user') && $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'check_in_at' => $this->check_in_at?->toIso8601String(),
            'check_out_at' => $this->check_out_at?->toIso8601String(),
            'mode' => $this->mode,
            'late' => $this->late,
            'distance' => $this->in_distance,
            'map' => $this->in_lat !== null ? "https://maps.google.com/?q={$this->in_lat},{$this->in_lng}" : null,
            'note' => $this->note,
            'minutes' => $this->minutes(),
            'auto_closed' => $this->auto_closed,
            'edited' => (bool) $this->edited_by,
        ];
    }
}

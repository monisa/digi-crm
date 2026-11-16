<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One line of the activity log. Written by the LogsActivity trait and by ActivityLog::record(). */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    /** Never stored as values: only "changed". */
    public const MASK = '/password|token|secret|credential|bank/i';

    /** Turned off while seeding / in bulk jobs that log a summary instead. */
    public static bool $paused = false;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Write a line. $by defaults to whoever is signed in (null = the system). */
    public static function record(string $action, ?string $label = null, ?Model $subject = null, ?array $changes = null, ?User $by = null, ?string $type = null): ?self
    {
        if (self::$paused) {
            return null;
        }
        $by ??= auth()->user();
        $request = app()->runningInConsole() ? null : request();

        return self::create([
            'user_id' => $by?->id,
            'user_name' => $by?->name,
            'action' => $action,
            'subject_type' => $type ?? ($subject ? self::typeOf($subject) : null),
            'subject_id' => $subject?->getKey(),
            'label' => $label !== null ? Str::limit($label, 195) : null,
            'changes' => $changes ?: null,
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    public static function typeOf(Model $m): string
    {
        return Str::snake(class_basename($m));
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'at' => $this->created_at?->toIso8601String(),
            'user' => $this->user_id || $this->user_name ? ['id' => $this->user_id, 'name' => $this->user?->name ?? $this->user_name] : null,
            'action' => $this->action,
            'type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'label' => $this->label,
            'changes' => $this->getAttribute('changes') ?? [], // not $this->changes: that is Eloquent's own property
            'ip' => $this->ip,
        ];
    }
}

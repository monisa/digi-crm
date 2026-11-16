<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Records created / updated / deleted in the activity log, with the fields that changed.
 * A model can set $activityIgnore (fields not worth logging) and $activityMask (fields
 * logged as "changed" without values, e.g. salary).
 */
trait LogsActivity
{
    private const ALWAYS_IGNORE = ['created_at', 'updated_at', 'remember_token', 'last_login_at', 'email_verified_at'];

    /** Fields holding a person / client id, shown by name instead. */
    private const PEOPLE = ['assigned_to', 'user_id', 'created_by', 'recorded_by', 'approved_by', 'approver_id', 'owner_id',
        'account_manager_id', 'raised_by', 'reviewed_by', 'generated_by'];

    private static array $nameCache = [];

    public static function bootLogsActivity(): void
    {
        static::created(fn ($m) => ActivityLog::record('created', $m->activityLabel(), $m));
        static::updated(function ($m) {
            $changes = $m->activityChanges();
            if ($changes) {
                ActivityLog::record('updated', $m->activityLabel(), $m, $changes);
            }
        });
        static::deleted(fn ($m) => ActivityLog::record('deleted', $m->activityLabel(), $m));
    }

    public function activityLabel(): string
    {
        foreach (['full_name', 'name', 'company_name', 'title', 'subject', 'invoice_no'] as $f) {
            if (filled($this->getAttribute($f))) {
                return (string) $this->getAttribute($f);
            }
        }

        return '#'.$this->getKey();
    }

    /** {field: [old, new]} for the fields that really changed. */
    public function activityChanges(): array
    {
        $ignore = array_merge(self::ALWAYS_IGNORE, $this->activityIgnore ?? []);
        $mask = $this->activityMask ?? [];
        self::$nameCache = [];
        $out = [];
        foreach ($this->getChanges() as $key => $new) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $old = $this->getOriginal($key);
            if (in_array('*', $mask, true) || in_array($key, $mask, true) || preg_match(ActivityLog::MASK, $key)) {
                $out[$key] = ['•••', 'changed'];

                continue;
            }
            $o = $this->activityValue($key, $old);
            $n = $this->activityValue($key, $new);
            if ($o !== $n) {
                $out[$key] = [$o, $n];
            }
        }

        return $out;
    }

    private function activityValue(string $key, mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            $v = $v->format('Y-m-d H:i');
        } elseif (is_bool($v)) {
            $v = $v ? 'Yes' : 'No';
        } elseif (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        $v = (string) $v;
        if (is_numeric($v)) {
            $name = match (true) {
                in_array($key, self::PEOPLE, true) => self::nameOf(User::class, (int) $v, 'name'),
                in_array($key, ['client_id', 'converted_client_id'], true) => self::nameOf(Client::class, (int) $v, 'company_name'),
                $key === 'employee_id' => self::nameOf(Employee::class, (int) $v, 'name'),
                $key === 'leave_type_id' => self::nameOf(LeaveType::class, (int) $v, 'name'),
                default => null,
            };
            if ($name !== null) {
                return $name;
            }
        }
        // Dates stored as "2026-11-16 00:00:00" read better without the time.
        $v = preg_replace('/^(\d{4}-\d{2}-\d{2}) 00:00:00$/', '$1', $v);

        return Str::limit($v, 150);
    }

    private static function nameOf(string $class, int $id, string $col): ?string
    {
        return self::$nameCache[$class.$id] ??= ($class::whereKey($id)->value($col) ?? '#'.$id);
    }
}

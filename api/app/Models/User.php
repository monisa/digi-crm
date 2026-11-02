<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'phone', 'company_name', 'role', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_CLIENT = 'client';

    public const STATUS_PENDING_ONBOARDING = 'pending_onboarding';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_data' => 'array',
            'onboarded_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notification_prefs' => 'array',
        ];
    }

    /** The HR record linked to this login (employees only). */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Whether this person wants emails for a notification type (on unless switched off). */
    public function wantsEmail(string $type): bool
    {
        return ($this->notification_prefs[$type] ?? true) !== false;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isClient(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    public function needsOnboarding(): bool
    {
        // The onboarding form is for clients only.
        return $this->isClient() && $this->status === self::STATUS_PENDING_ONBOARDING;
    }

    public function hasPermission(string $key): bool
    {
        return app(Permissions::class)->allows($this, $key);
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return app(Permissions::class)->for($this);
    }

    /** Shape returned to the Angular app. */
    public function toAuthArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company_name' => $this->company_name,
            'role' => $this->role,
            'status' => $this->status,
            'needs_onboarding' => $this->needsOnboarding(),
            'leads_scope' => $this->isAdmin() ? 'all' : ($this->leads_scope ?? 'assigned'),
            'clients_scope' => $this->isAdmin() ? 'all' : ($this->clients_scope ?? 'assigned'),
            'client_id' => $this->client_id,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}

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
        ];
    }

    /** The HR record linked to this login (employees only). */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
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
        return $this->status === self::STATUS_PENDING_ONBOARDING;
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
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}

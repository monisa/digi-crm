<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** Never send shared passwords in normal responses. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['onboarding_data' => 'array', 'credentials' => 'encrypted:array'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ClientFile::class)->latest();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Clients a user may see: admins and "all clients" staff see all, other staff
     * only the clients they manage, client logins only their own company.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isClient()) {
            return $q->where('id', $user->client_id ?? 0);
        }

        return $user->isAdmin() || $user->clients_scope === 'all' ? $q : $q->where('account_manager_id', $user->id);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'industry' => $this->industry,
            'website' => $this->website,
            'gstin' => $this->gstin,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'status' => $this->status,
            'account_manager' => $this->manager ? ['id' => $this->manager->id, 'name' => $this->manager->name] : null,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_contacted_at' => 'datetime',
            'next_followup_at' => 'datetime',
            'custom_fields' => 'array',
            'raw_payload' => 'array',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('id');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    /** Leads this user may see: admins and "all leads" users see everything, others only their own. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->isAdmin() || $user->leads_scope === 'all' ? $q : $q->where('assigned_to', $user->id);
    }

    public function log(?User $by, string $type, ?string $note = null, ?string $outcome = null): LeadActivity
    {
        return $this->activities()->create(['user_id' => $by?->id, 'type' => $type, 'note' => $note, 'outcome' => $outcome]);
    }

    public function toApiArray(bool $full = false): array
    {
        $data = [
            'id' => $this->id,
            'date' => $this->created_at?->toIso8601String(),
            'ad_name' => $this->ad_name,
            'campaign_name' => $this->campaign_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'city' => $this->city,
            'call_status' => $this->call_status,
            'status' => $this->status,
            'package' => $this->package,
            'requirement' => $this->requirement,
            'followup_status' => $this->followup_status,
            'next_followup_at' => $this->next_followup_at?->toIso8601String(),
            'notes' => $this->notes,
            'source' => $this->source,
            'platform' => $this->platform,
            'assigned_to' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null,
            'first_contacted_at' => $this->first_contacted_at?->toIso8601String(),
            'lost_reason' => $this->lost_reason,
            'converted_client_id' => $this->converted_client_id,
        ];

        if ($full) {
            $data['adset_name'] = $this->adset_name;
            $data['form_name'] = $this->form_name;
            $data['custom_fields'] = $this->custom_fields ?? [];
            $data['activities'] = $this->activities->load('user:id,name')->map->toApiArray();
        }

        return $data;
    }
}

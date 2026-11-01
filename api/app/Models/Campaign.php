<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date', 'results_updated_at' => 'datetime',
            'budget' => 'float', 'spend' => 'float',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CampaignFile::class)->latest();
    }

    /** Same visibility as the client: client logins see their own, staff the clients they manage. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->whereIn('client_id', Client::visibleTo($user)->select('id'));
    }

    public function toApiArray(bool $full = false): array
    {
        $cpl = $this->spend && $this->leads ? round($this->spend / $this->leads, 2) : null;
        $data = [
            'id' => $this->id,
            'client' => $this->client ? ['id' => $this->client->id, 'company_name' => $this->client->company_name] : null,
            'name' => $this->name,
            'platform' => $this->platform,
            'objective' => $this->objective,
            'budget' => $this->budget,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'results' => [
                'spend' => $this->spend, 'reach' => $this->reach, 'impressions' => $this->impressions, 'clicks' => $this->clicks,
                'leads' => $this->leads, 'conversions' => $this->conversions, 'cost_per_lead' => $cpl,
                'ctr' => $this->clicks && $this->impressions ? round($this->clicks / $this->impressions * 100, 2) : null,
                'updated_at' => $this->results_updated_at?->toIso8601String(),
            ],
            'summary' => $this->summary,
            'owner' => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null,
        ];
        if ($full) {
            $data['files'] = $this->files->map(fn (CampaignFile $f) => [
                'id' => $f->id, 'title' => $f->title, 'name' => $f->original_name, 'size' => $f->size, 'uploaded_at' => $f->created_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }
}

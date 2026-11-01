<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public const OPEN = ['open', 'in_progress', 'waiting'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'last_reply_at' => 'datetime', 'awaiting_staff' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $q->whereIn('client_id', Client::visibleTo($user)->select('id'));
    }

    public function toApiArray(?User $viewer = null, bool $full = false): array
    {
        $data = [
            'id' => $this->id,
            'number' => 'T-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT),
            'subject' => $this->subject,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'client' => $this->client ? ['id' => $this->client->id, 'company_name' => $this->client->company_name] : null,
            'raised_by' => $this->raiser?->name,
            'assigned_to' => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null,
            'awaiting_staff' => $this->awaiting_staff,
            'last_reply_at' => ($this->last_reply_at ?? $this->created_at)?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
        if ($full) {
            $isClient = $viewer?->isClient();
            $data['messages'] = $this->messages->load('user:id,name,role')
                ->reject(fn (TicketMessage $m) => $isClient && $m->internal)
                ->map(fn (TicketMessage $m) => [
                    'id' => $m->id,
                    'body' => $m->body,
                    'internal' => $m->internal,
                    'by' => $m->user?->name,
                    'from_client' => $m->user?->role === 'client',
                    'mine' => $viewer && $m->user_id === $viewer->id,
                    'attachment' => $m->attachment_name ? ['id' => $m->id, 'name' => $m->attachment_name] : null,
                    'at' => $m->created_at?->toIso8601String(),
                ])->values();
        }

        return $data;
    }
}

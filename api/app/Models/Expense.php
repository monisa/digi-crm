<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date', 'amount' => 'float', 'gst' => 'float', 'reimbursable' => 'boolean',
            'reimbursed_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** People who approve expenses see everything; others see the expenses they added. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->hasPermission('expenses.approve') ? $q : $q->where('created_by', $user->id);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'spent_on' => $this->spent_on->toDateString(),
            'category' => $this->category,
            'amount' => $this->amount,
            'gst' => $this->gst,
            'payment_mode' => $this->payment_mode,
            'vendor' => $this->vendor,
            'client' => $this->client ? ['id' => $this->client->id, 'company_name' => $this->client->company_name] : null,
            'notes' => $this->notes,
            'status' => $this->status,
            'reimbursable' => $this->reimbursable,
            'reimbursed_at' => $this->reimbursed_at?->toIso8601String(),
            'receipt' => $this->receipt_name ? ['name' => $this->receipt_name] : null,
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'approved_by' => $this->approver?->name,
            'review_note' => $this->review_note,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

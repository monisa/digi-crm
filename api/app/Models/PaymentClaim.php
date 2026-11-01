<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's "I've paid" note (UPI transaction ID), confirmed by the admin against the bank statement. */
class PaymentClaim extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'invoice' => $this->relationLoaded('invoice') ? ['id' => $this->invoice_id, 'invoice_no' => $this->invoice?->invoice_no, 'balance' => $this->invoice?->balance()] : ['id' => $this->invoice_id],
            'client' => $this->relationLoaded('client') ? $this->client?->company_name : null,
            'amount' => $this->amount,
            'paid_on' => $this->paid_on->toDateString(),
            'mode' => $this->mode,
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->status,
            'submitted_by' => $this->submitter?->name,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

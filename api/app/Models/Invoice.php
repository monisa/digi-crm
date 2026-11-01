<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'emailed_at' => 'datetime',
            'subtotal' => 'float', 'cgst' => 'float', 'sgst' => 'float', 'igst' => 'float', 'tax' => 'float',
            'total' => 'float', 'amount_paid' => 'float', 'gst_rate' => 'float',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(PaymentClaim::class)->latest();
    }

    /** Invoices a user may see: internal staff by their client visibility, client logins only their own issued invoices. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isClient()) {
            return $q->where('client_id', $user->client_id ?? 0)->where('status', '!=', 'draft');
        }

        return $user->isAdmin() || $user->clients_scope === 'all'
            ? $q
            : $q->whereHas('client', fn ($c) => $c->where('account_manager_id', $user->id));
    }

    public function balance(): float
    {
        return round(max(0, $this->total - $this->amount_paid), 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['unpaid', 'partial'], true) && $this->due_date?->lt(today());
    }

    /** Re-derive status from payments (never touches drafts or cancelled invoices). */
    public function refreshPaidStatus(): void
    {
        $this->amount_paid = round((float) $this->payments()->sum('amount'), 2);
        if (! in_array($this->status, ['draft', 'cancelled'], true)) {
            $this->status = $this->amount_paid <= 0 ? 'unpaid' : ($this->amount_paid + 0.009 >= $this->total ? 'paid' : 'partial');
        }
        $this->save();
    }

    public function toApiArray(bool $full = false): array
    {
        $data = [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'client' => $this->client ? ['id' => $this->client->id, 'company_name' => $this->client->company_name] : null,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance(),
            'status' => $this->status,
            'overdue' => $this->isOverdue(),
        ];
        if ($full) {
            $data += [
                'place_of_supply' => $this->place_of_supply,
                'gst_type' => $this->gst_type,
                'gst_rate' => $this->gst_rate,
                'cgst' => $this->cgst, 'sgst' => $this->sgst, 'igst' => $this->igst,
                'notes' => $this->notes,
                'issued_at' => $this->issued_at?->toIso8601String(),
                'emailed_at' => $this->emailed_at?->toIso8601String(),
                'items' => $this->items->map->only(['id', 'description', 'sac', 'qty', 'rate', 'amount'])->values(),
                'payments' => $this->payments->map(fn (Payment $p) => [
                    'id' => $p->id, 'amount' => $p->amount, 'paid_on' => $p->paid_on->toDateString(), 'mode' => $p->mode, 'reference' => $p->reference,
                ])->values(),
                'claims' => $this->claims->map->toApiArray()->values(),
                'client_detail' => $this->client?->toApiArray(),
            ];
        }

        return $data;
    }
}

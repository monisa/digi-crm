<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private Gst $gst, private CompanyProfile $company, private UpiQr $upi) {}

    /** Create or update a draft invoice with its items and GST. */
    public function saveDraft(array $data, ?Invoice $invoice, User $by): Invoice
    {
        if ($invoice && $invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Issued invoices cannot be edited. Cancel it and create a new one.']);
        }
        $client = Client::findOrFail($data['client_id']);
        $c = $this->company->get();
        $type = $data['gst_type'] ?? $this->gst->typeFor($client->gstin, $client->state);
        $rate = $type === 'export' ? 0 : (float) ($data['gst_rate'] ?? $c['default_gst_rate']);

        $items = collect($data['items'])->values()->map(fn ($i, $n) => [
            'description' => $i['description'],
            'sac' => $i['sac'] ?? $c['default_sac'],
            'qty' => (float) $i['qty'],
            'rate' => (float) $i['rate'],
            'amount' => round((float) $i['qty'] * (float) $i['rate'], 2),
            'sort' => $n,
        ]);
        $totals = $this->gst->totals($items->sum('amount'), $type, $rate);
        $issue = CarbonImmutable::parse($data['issue_date'] ?? today());

        return DB::transaction(function () use ($invoice, $client, $data, $type, $rate, $items, $totals, $issue, $c, $by) {
            $invoice ??= new Invoice(['status' => 'draft', 'created_by' => $by->id, 'amount_paid' => 0]);
            $invoice->fill([
                'client_id' => $client->id,
                'issue_date' => $issue,
                'due_date' => $data['due_date'] ?? $issue->addDays((int) $c['payment_terms_days']),
                'place_of_supply' => $type === 'export' ? 'Outside India' : ($client->state ?: $c['state']),
                'gst_type' => $type,
                'gst_rate' => $rate,
                'notes' => $data['notes'] ?? null,
                ...$totals,
            ])->save();
            $invoice->items()->delete();
            $invoice->items()->createMany($items->all());

            return $invoice->fresh(['items', 'client']);
        });
    }

    /** Give the draft its number (DFP/2026-27/001, per financial year) and make it payable. */
    public function issue(Invoice $invoice): Invoice
    {
        abort_unless($invoice->status === 'draft', 422, 'This invoice has already been issued.');
        abort_if($invoice->total <= 0, 422, 'Add at least one item with an amount.');

        return DB::transaction(function () use ($invoice) {
            $d = CarbonImmutable::parse($invoice->issue_date);
            $fy = $d->month >= 4 ? $d->year : $d->year - 1;
            $prefix = sprintf('%s/%d-%02d/', $this->company->get()['invoice_prefix'] ?: 'DFP', $fy, ($fy + 1) % 100);

            $last = Invoice::where('invoice_no', 'like', $prefix.'%')->lockForUpdate()->pluck('invoice_no')
                ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;

            $invoice->update(['invoice_no' => $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT), 'status' => 'unpaid', 'issued_at' => now()]);
            $invoice->refreshPaidStatus();
            $n = app(\App\Services\Notify::class);
            $n->send($n->clientUsers($invoice->client_id), 'invoice_issued', 'New invoice '.$invoice->invoice_no,
                'Rs. '.number_format($invoice->total, 2).', due '.$invoice->due_date->format('d M Y').'. Pay by scanning the UPI QR on the invoice.',
                '/invoices/'.$invoice->id);

            return $invoice->fresh(['items', 'client']);
        });
    }

    public function pdf(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        $invoice->loadMissing(['items', 'client', 'payments']);
        $logo = public_path('payslip-logo.png');

        return Pdf::loadView('pdf.invoice', [
            'inv' => $invoice,
            'c' => $this->company->get(),
            'client' => $invoice->client,
            'qr' => $this->upi->dataUri($invoice),
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logo)) : null,
        ])->setPaper('a4');
    }

    public function fileName(Invoice $invoice): string
    {
        return 'Invoice-'.str_replace('/', '-', $invoice->invoice_no ?? 'DRAFT-'.$invoice->id).'.pdf';
    }
}

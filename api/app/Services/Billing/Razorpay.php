<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\OnlinePaymentLink;
use App\Models\Payment;
use App\Services\Notify;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Online payment of invoices through Razorpay Payment Links (UPI, cards,
 * net banking, wallets). A paid link is recorded as a payment on the invoice
 * automatically, from Razorpay's webhook or the customer's return to the CRM,
 * whichever comes first.
 */
class Razorpay
{
    private const API = 'https://api.razorpay.com/v1';

    public function configured(): bool
    {
        return filled(config('services.razorpay.key_id')) && filled(config('services.razorpay.key_secret'));
    }

    /** A link for the balance now due; reused while the amount is unchanged. */
    public function linkFor(Invoice $invoice): OnlinePaymentLink
    {
        if (! $this->configured()) {
            throw new RuntimeException('Online payment is not set up yet.');
        }
        $balance = round($invoice->balance(), 2);
        if ($balance < 1) {
            throw new RuntimeException('Nothing is due on this invoice.');
        }

        $existing = $invoice->onlineLinks()->where('status', 'created')->where('amount', $balance)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addHour()))->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $client = $invoice->client;
        $phone = preg_replace('/\D/', '', (string) $client?->phone);
        $expires = now()->addDays(30);
        $body = array_filter([
            'amount' => (int) round($balance * 100), // paise
            'currency' => 'INR',
            'accept_partial' => false,
            'reference_id' => substr('INV'.$invoice->id.'-'.now()->format('ymdHis'), 0, 40),
            'description' => substr('Invoice '.($invoice->invoice_no ?? '#'.$invoice->id), 0, 2048),
            'customer' => array_filter([
                'name' => $client?->contact_name ?: $client?->company_name,
                'email' => $client?->email,
                'contact' => $phone ? '+'.(strlen($phone) === 10 ? '91'.$phone : $phone) : null,
            ]),
            'notify' => ['sms' => false, 'email' => false], // the CRM sends the link itself
            'reminder_enable' => false,
            'notes' => ['invoice_id' => (string) $invoice->id, 'invoice_no' => (string) $invoice->invoice_no],
            'callback_url' => rtrim(config('app.frontend_url'), '/').'/pay/done',
            'callback_method' => 'get',
            'expire_by' => $expires->timestamp,
        ], fn ($v) => $v !== null && $v !== []);

        try {
            $res = Http::withBasicAuth(config('services.razorpay.key_id'), config('services.razorpay.key_secret'))
                ->acceptJson()->timeout(20)->post(self::API.'/payment_links', $body);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Razorpay. Please try again in a minute.');
        }
        if (! $res->successful()) {
            $msg = $res->json('error.description') ?: 'Razorpay refused the request.';
            throw new RuntimeException($res->status() === 401 ? 'Razorpay rejected the API keys. Check RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET.' : $msg);
        }

        return $invoice->onlineLinks()->create([
            'link_id' => $res->json('id'),
            'url' => $res->json('short_url'),
            'amount' => $balance,
            'expires_at' => $expires,
        ]);
    }

    /**
     * Ask Razorpay whether a link was paid and record it (for when neither the
     * webhook nor the payer's return reached the CRM). Returns payments recorded.
     */
    public function sync(OnlinePaymentLink $link): int
    {
        if (! $this->configured() || $link->status === 'paid') {
            return 0;
        }
        try {
            $res = Http::withBasicAuth(config('services.razorpay.key_id'), config('services.razorpay.key_secret'))
                ->acceptJson()->timeout(15)->get(self::API.'/payment_links/'.$link->link_id);
        } catch (ConnectionException) {
            return 0;
        }
        if (! $res->successful()) {
            return 0;
        }
        $status = (string) $res->json('status');
        if (in_array($status, ['cancelled', 'expired'], true)) {
            $link->update(['status' => $status]);

            return 0;
        }
        $n = 0;
        foreach ((array) $res->json('payments', []) as $p) {
            if (($p['status'] ?? '') === 'captured' && ! empty($p['payment_id'])) {
                $made = $this->markPaid($link->link_id, $p['payment_id'], isset($p['amount']) ? $p['amount'] / 100 : null, null, $p['method'] ?? null);
                $n += $made?->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * Record a payment made outside the CRM (e.g. a link made in the Razorpay
     * dashboard) by its payment id, after checking it with Razorpay.
     */
    public function importPayment(Invoice $invoice, string $paymentId): Payment
    {
        if (! $this->configured()) {
            throw new RuntimeException('Add the Razorpay keys first (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET), or use “Record payment”.');
        }
        if (Payment::where('reference', $paymentId)->exists()) {
            throw new RuntimeException('This Razorpay payment is already recorded.');
        }
        try {
            $res = Http::withBasicAuth(config('services.razorpay.key_id'), config('services.razorpay.key_secret'))
                ->acceptJson()->timeout(15)->get(self::API.'/payments/'.$paymentId);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Razorpay. Please try again in a minute.');
        }
        if ($res->status() === 404 || $res->status() === 400) {
            throw new RuntimeException('Razorpay has no payment with this ID. It starts with pay_ (Razorpay → Transactions → Payments).');
        }
        if (! $res->successful()) {
            throw new RuntimeException($res->status() === 401 ? 'Razorpay rejected the API keys.' : ($res->json('error.description') ?: 'Razorpay refused the request.'));
        }
        if ($res->json('status') !== 'captured') {
            throw new RuntimeException('This payment is “'.$res->json('status').'” on Razorpay, not completed (captured).');
        }
        $amount = round($res->json('amount') / 100, 2);
        if ($amount > $invoice->balance() + 0.009) {
            throw new RuntimeException('This payment (Rs. '.number_format($amount, 2).') is more than the balance due (Rs. '.number_format($invoice->balance(), 2).').');
        }

        return DB::transaction(function () use ($invoice, $paymentId, $amount, $res) {
            $p = $invoice->payments()->create([
                'client_id' => $invoice->client_id, 'amount' => $amount,
                'paid_on' => \Illuminate\Support\Carbon::createFromTimestamp((int) $res->json('created_at'), config('app.timezone'))->toDateString(),
                'mode' => 'online', 'reference' => $paymentId,
                'note' => 'Razorpay payment added by ID'.($res->json('method') ? ' ('.$res->json('method').')' : ''),
                'gateway_fee' => $res->json('fee') !== null ? $res->json('fee') / 100 : null,
                'recorded_by' => request()->user()?->id,
            ]);
            $invoice->refreshPaidStatus();

            return $p;
        });
    }

    /** X-Razorpay-Signature on webhooks: HMAC-SHA256 of the raw body with the webhook secret. */
    public function validWebhook(string $body, ?string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        return $secret !== '' && $signature && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    /** Signature Razorpay adds when sending the customer back to callback_url. */
    public function validCallback(array $p): bool
    {
        $secret = (string) config('services.razorpay.key_secret');
        $payload = ($p['razorpay_payment_link_id'] ?? '').'|'.($p['razorpay_payment_link_reference_id'] ?? '').'|'
            .($p['razorpay_payment_link_status'] ?? '').'|'.($p['razorpay_payment_id'] ?? '');

        return $secret !== '' && isset($p['razorpay_signature']) && hash_equals(hash_hmac('sha256', $payload, $secret), (string) $p['razorpay_signature']);
    }

    /**
     * Mark a link paid and record the payment once (safe to call twice).
     * Returns the payment, or null if the link is unknown.
     */
    public function markPaid(string $linkId, string $paymentId, ?float $amount = null, ?float $fee = null, ?string $method = null): ?Payment
    {
        $payment = DB::transaction(function () use ($linkId, $paymentId, $amount, $fee, $method) {
            $link = OnlinePaymentLink::where('link_id', $linkId)->lockForUpdate()->first();
            if (! $link) {
                return null;
            }
            $invoice = Invoice::lockForUpdate()->find($link->invoice_id);
            if ($done = Payment::where('invoice_id', $invoice->id)->where('reference', $paymentId)->first()) {
                return $done; // already recorded
            }

            $payment = $invoice->payments()->create([
                'client_id' => $invoice->client_id,
                'amount' => $amount ?? $link->amount,
                'paid_on' => today(),
                'mode' => 'online',
                'reference' => $paymentId,
                'note' => 'Paid online via Razorpay'.($method ? ' ('.$method.')' : ''),
                'gateway_fee' => $fee,
            ]);
            $link->update(['status' => 'paid']);
            $invoice->refreshPaidStatus();
            $payment->wasRecentlyCreated = true;

            return $payment;
        });

        if ($payment?->wasRecentlyCreated) {
            $invoice = Invoice::find($payment->invoice_id);
            $no = $invoice->invoice_no ?? '#'.$invoice->id;
            $amt = 'Rs. '.number_format($payment->amount, 2);
            $n = app(Notify::class);
            $n->send($n->staffWith('invoices.view'), 'payment_online', "$amt paid online for $no",
                ($invoice->client?->company_name ?? 'A client').' paid through Razorpay (ref '.$paymentId.').', '/invoices/'.$invoice->id);
            $n->send($n->clientUsers($invoice->client_id), 'payment_decided', 'Payment received, thank you!',
                "We received $amt for invoice $no.", '/invoices/'.$invoice->id);
        }

        return $payment;
    }
}

<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** "I've paid" (UPI / bank transfer) → admin checks the bank → confirm or reject. */
class PaymentClaimController extends Controller
{
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();
        abort_unless(Invoice::visibleTo($user)->whereKey($invoice->id)->exists(), 404);
        abort_unless(in_array($invoice->status, ['unpaid', 'partial'], true), 422, 'This invoice is not waiting for payment.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:'.$invoice->balance()],
            'paid_on' => ['required', 'date', 'before_or_equal:today', 'after:-60 days'],
            'mode' => ['required', Rule::in(['upi', 'bank_transfer'])],
            'reference' => ['required', 'string', 'min:6', 'max:60', 'regex:/^[A-Za-z0-9\-\/ ]+$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'reference.required' => 'Enter the UPI transaction ID (UTR) from your payment app.',
            'reference.min' => 'The transaction ID looks too short. In GPay it is the 12-digit UPI transaction ID.',
            'amount.max' => 'The amount is more than the balance due (Rs. '.number_format($invoice->balance(), 2).').',
        ]);

        $dup = PaymentClaim::where('reference', $data['reference'])->whereIn('status', ['pending', 'confirmed'])->exists();
        if ($dup) {
            throw ValidationException::withMessages(['reference' => 'This transaction ID has already been submitted.']);
        }

        $claim = $invoice->claims()->create([...$data, 'client_id' => $invoice->client_id, 'submitted_by' => $user->id, 'status' => 'pending']);
        $n = app(\App\Services\Notify::class);
        $n->send($n->staffWith('invoices.approve'), 'payment_claim',
            $invoice->client->company_name.' says they paid Rs. '.number_format($claim->amount, 2),
            'For '.$invoice->invoice_no.' by '.strtoupper($claim->mode === 'upi' ? 'UPI' : 'bank transfer').', reference '.$claim->reference.'. Check the bank, then confirm.',
            '/invoices?tab=claims', true, $user);

        return response()->json($claim->load('invoice', 'client', 'submitter')->toApiArray(), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'confirmed', 'rejected'])]])['status'] ?? 'pending';
        $claims = PaymentClaim::with(['invoice', 'client', 'submitter'])->where('status', $status)
            ->whereIn('invoice_id', Invoice::visibleTo($request->user())->select('id'))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $claims->map->toApiArray()]);
    }

    public function decide(Request $request, PaymentClaim $claim, string $decision): JsonResponse
    {
        abort_unless(Invoice::visibleTo($request->user())->whereKey($claim->invoice_id)->exists(), 404);
        abort_unless($claim->status === 'pending', 422, 'This payment has already been '.$claim->status.'.');
        $data = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:500']],
            ['note.required' => 'Tell the client why (e.g. "Not received in bank yet").']);

        DB::transaction(function () use ($claim, $decision, $data, $request) {
            $invoice = $claim->invoice()->lockForUpdate()->first();
            $paymentId = null;
            if ($decision === 'confirm') {
                abort_if($claim->amount > $invoice->balance() + 0.009, 422, 'This is more than the balance now due. Reject it or record the right amount by hand.');
                $paymentId = $invoice->payments()->create([
                    'client_id' => $claim->client_id, 'amount' => $claim->amount, 'paid_on' => $claim->paid_on,
                    'mode' => $claim->mode, 'reference' => $claim->reference, 'recorded_by' => $request->user()->id,
                    'note' => 'Confirmed from client payment note',
                ])->id;
                $invoice->refreshPaidStatus();
            }
            $claim->update([
                'status' => $decision === 'confirm' ? 'confirmed' : 'rejected',
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null, 'payment_id' => $paymentId,
            ]);
        });

        $claim->refresh()->load('invoice');
        $ok = $claim->status === 'confirmed';
        $n = app(\App\Services\Notify::class);
        $n->send($n->clientUsers($claim->client_id), 'payment_decided',
            $ok ? 'Payment received, thank you!' : 'We could not find your payment yet',
            $ok ? 'Rs. '.number_format($claim->amount, 2).' for '.$claim->invoice->invoice_no.' is confirmed.'
                : 'Your payment note for '.$claim->invoice->invoice_no.' (ref '.$claim->reference.'): '.$claim->review_note,
            '/invoices/'.$claim->invoice_id, true, $request->user());

        return response()->json($claim->fresh(['invoice', 'client', 'submitter'])->toApiArray());
    }
}

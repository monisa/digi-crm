<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\OnlinePaymentLink;
use App\Services\Billing\Razorpay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** "Pay online" for invoices through Razorpay. */
class OnlinePaymentController extends Controller
{
    public function __construct(private Razorpay $razorpay) {}

    /** The payment link for an invoice (clients for their own, staff for any they can see). */
    public function link(Request $request, Invoice $invoice): JsonResponse
    {
        abort_unless(Invoice::visibleTo($request->user())->whereKey($invoice->id)->exists(), 404);
        abort_if(in_array($invoice->status, ['draft', 'cancelled', 'paid'], true), 422, 'This invoice cannot be paid online.');

        try {
            $link = $this->razorpay->linkFor($invoice->load('client'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['url' => $link->url, 'amount' => $link->amount]);
    }

    /** The customer is back from Razorpay: record the payment straight away (signed by Razorpay). */
    public function callback(Request $request): JsonResponse
    {
        $p = $request->validate([
            'razorpay_payment_id' => ['nullable', 'string', 'max:60'],
            'razorpay_payment_link_id' => ['required', 'string', 'max:60'],
            'razorpay_payment_link_reference_id' => ['nullable', 'string', 'max:60'],
            'razorpay_payment_link_status' => ['required', 'string', 'max:30'],
            'razorpay_signature' => ['nullable', 'string', 'max:200'],
        ]);
        $link = OnlinePaymentLink::where('link_id', $p['razorpay_payment_link_id'])->first();
        abort_unless($link, 404, 'Payment not found.');

        $paid = $p['razorpay_payment_link_status'] === 'paid' && ! empty($p['razorpay_payment_id']);
        if ($paid) {
            abort_unless($this->razorpay->validCallback($p), 422, 'The payment could not be verified. If money was taken, it will still show within a few minutes.');
            $this->razorpay->markPaid($link->link_id, $p['razorpay_payment_id']);
        }
        $invoice = $link->invoice;

        return response()->json([
            'paid' => $paid,
            'invoice_id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'amount' => $link->amount,
            'balance' => $invoice->balance(),
        ]);
    }

    /** Razorpay webhook: payment_link.paid (and payment.captured for links). */
    public function webhook(Request $request): Response
    {
        if (! $this->razorpay->validWebhook($request->getContent(), $request->header('X-Razorpay-Signature'))) {
            Log::warning('Razorpay webhook with a bad signature was ignored.');

            return response('Invalid signature', 400);
        }

        if ($request->input('event') === 'payment_link.paid') {
            $link = $request->input('payload.payment_link.entity');
            $pay = $request->input('payload.payment.entity');
            if (isset($link['id'], $pay['id'])) {
                $this->razorpay->markPaid(
                    $link['id'], $pay['id'],
                    isset($pay['amount']) ? $pay['amount'] / 100 : null,
                    isset($pay['fee']) ? $pay['fee'] / 100 : null,
                    $pay['method'] ?? null,
                );
            }
        }

        return response('OK', 200);
    }
}

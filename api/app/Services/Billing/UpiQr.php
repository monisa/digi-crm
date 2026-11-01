<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** UPI "scan and pay" QR for an invoice: works with GPay, PhonePe, Paytm and bank apps. */
class UpiQr
{
    public function __construct(private CompanyProfile $company) {}

    public function link(Invoice $invoice): ?string
    {
        $c = $this->company->get();
        $due = round($invoice->total - $invoice->amount_paid, 2);
        if (! $c['upi_id'] || $due <= 0 || ! $invoice->invoice_no) {
            return null;
        }

        return 'upi://pay?'.http_build_query([
            'pa' => $c['upi_id'],
            'pn' => $c['upi_name'] ?: $c['legal_name'],
            'am' => number_format($due, 2, '.', ''),
            'cu' => 'INR',
            'tn' => 'Invoice '.$invoice->invoice_no,
            'tr' => preg_replace('/[^A-Za-z0-9]/', '', $invoice->invoice_no),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** PNG data URI, or null when no UPI ID is set / nothing is due. */
    public function dataUri(Invoice $invoice): ?string
    {
        $link = $this->link($invoice);
        if (! $link) {
            return null;
        }

        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'scale' => 8,
            'quietzoneSize' => 2,
            'outputBase64' => true,
        ])))->render($link);
    }
}

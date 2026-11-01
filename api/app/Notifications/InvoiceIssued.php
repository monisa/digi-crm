<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Services\Billing\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails the invoice PDF to the client. */
class InvoiceIssued extends Notification
{
    use Queueable;

    public function __construct(private Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $svc = app(InvoiceService::class);
        $inv = $this->invoice;
        $amount = 'Rs. '.number_format($inv->balance(), 2);

        return (new MailMessage)
            ->subject("Invoice {$inv->invoice_no} from DigiFox Pro Solutions")
            ->greeting('Hello '.($inv->client->contact_name ?: $inv->client->company_name).',')
            ->line("Please find attached invoice {$inv->invoice_no} for {$amount}, due on ".$inv->due_date->format('d M Y').'.')
            ->line('You can pay by scanning the UPI QR code on the invoice with GPay, PhonePe or any UPI app, or by bank transfer.')
            ->action('View and pay online', rtrim(config('app.frontend_url'), '/').'/invoices/'.$inv->id)
            ->line('After paying, use "I have paid" on the invoice page and enter the UPI transaction ID so we can confirm it quickly.')
            ->attachData($svc->pdf($inv)->output(), $svc->fileName($inv), ['mime' => 'application/pdf']);
    }
}

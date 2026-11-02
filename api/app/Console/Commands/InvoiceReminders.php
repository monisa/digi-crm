<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Notifications\CrmAlert;
use App\Services\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/** Weekly reminder for overdue invoices (at most 4), to the client's logins or billing email. */
class InvoiceReminders extends Command
{
    protected $signature = 'crm:invoice-reminders';

    protected $description = 'Email reminders for overdue invoices';

    public function handle(Notify $notify): int
    {
        $invoices = Invoice::with('client')->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', '<', today())->where('reminders_sent', '<', 4)
            ->where(fn ($q) => $q->whereNull('last_reminder_at')->orWhere('last_reminder_at', '<=', now()->subDays(7)))
            ->get();

        foreach ($invoices as $inv) {
            $days = (int) $inv->due_date->diffInDays(today());
            $title = 'Reminder: invoice '.$inv->invoice_no.' is overdue';
            $body = 'Rs. '.number_format($inv->balance(), 2).' was due on '.$inv->due_date->format('d M Y')." ($days days ago). Please pay by scanning the UPI QR on the invoice, then tap \"I've paid\". If you have already paid, please ignore this.";
            $link = '/invoices/'.$inv->id;

            $logins = $notify->clientUsers($inv->client_id);
            if ($logins->isNotEmpty()) {
                $notify->send($logins, 'invoice_overdue', $title, $body, $link, true);
            } elseif ($inv->client?->email) {
                Notification::route('mail', $inv->client->email)->notify(new CrmAlert('invoice_overdue', $title, $body, $link, true));
            } else {
                continue;
            }
            $inv->forceFill(['reminders_sent' => $inv->reminders_sent + 1, 'last_reminder_at' => now()])->saveQuietly();
        }
        $this->info(count($invoices).' reminder(s) considered.');

        return self::SUCCESS;
    }
}

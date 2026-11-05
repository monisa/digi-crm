<?php

namespace App\Console\Commands;

use App\Models\OnlinePaymentLink;
use App\Services\Billing\Razorpay;
use Illuminate\Console\Command;

/** Every few minutes: ask Razorpay about open payment links, in case a webhook was missed. */
class SyncRazorpay extends Command
{
    protected $signature = 'crm:razorpay-sync';

    protected $description = 'Record Razorpay payment-link payments the webhook missed';

    public function handle(Razorpay $razorpay): int
    {
        if (! $razorpay->configured()) {
            return self::SUCCESS;
        }
        $n = 0;
        OnlinePaymentLink::where('status', 'created')->where('created_at', '>=', now()->subDays(35))
            ->orderByDesc('id')->limit(100)->get()->each(function ($l) use ($razorpay, &$n) {
                $n += $razorpay->sync($l);
            });
        $this->info("Recorded $n payment(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\MetaPage;
use App\Services\Leads\MetaLeads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fetches a lead announced by Meta's webhook (run by the queue worker from the cron). */
class ImportMetaLead implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $leadgenId, public string $pageId) {}

    public function handle(MetaLeads $meta): void
    {
        $page = MetaPage::where('page_id', $this->pageId)->first();
        if ($page) {
            $meta->import($this->leadgenId, $page);
        }
    }
}

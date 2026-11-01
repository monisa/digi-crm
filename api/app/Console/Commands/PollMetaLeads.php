<?php

namespace App\Console\Commands;

use App\Services\Leads\MetaLeads;
use Illuminate\Console\Command;

class PollMetaLeads extends Command
{
    protected $signature = 'meta:poll {--hours=24 : How far back to look the first time}';

    protected $description = 'Fetch recent Meta lead-form leads that the webhook may have missed';

    public function handle(MetaLeads $meta): int
    {
        $this->info($meta->poll((int) $this->option('hours')).' new lead(s) imported.');

        return self::SUCCESS;
    }
}

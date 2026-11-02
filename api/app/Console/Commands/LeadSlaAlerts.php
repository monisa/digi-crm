<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\Notify;
use Illuminate\Console\Command;

/** Alerts the assignee and admins once when a new lead has waited longer than the response time. */
class LeadSlaAlerts extends Command
{
    protected $signature = 'crm:lead-sla';

    protected $description = 'Notify about new leads not contacted within the response time';

    public function handle(Notify $notify): int
    {
        $sla = config('crm.lead_sla_minutes');
        $leads = Lead::with('assignee')
            ->where('status', 'new')->whereNull('first_contacted_at')->whereNull('sla_alerted_at')
            ->where('created_at', '<=', now()->subMinutes($sla))
            ->where('created_at', '>=', now()->subDays(2)) // never flood people about old imported leads
            ->limit(200)->get();

        foreach ($leads as $lead) {
            $waited = (int) $lead->created_at->diffInMinutes(now());
            $notify->send(collect([$lead->assignee])->merge($notify->admins()), 'lead_uncontacted',
                'Not contacted yet: '.$lead->full_name,
                'This lead has waited '.($waited >= 60 ? intdiv($waited, 60).' h '.($waited % 60).' min' : $waited.' min')
                    .($lead->assignee ? ' (assigned to '.$lead->assignee->name.')' : ' and is not assigned to anyone').'. Call now while they are interested.',
                '/leads?view=uncontacted&open='.$lead->id, true);
            $lead->forceFill(['sla_alerted_at' => now()])->saveQuietly();
        }
        $this->info(count($leads).' alert(s) sent.');

        return self::SUCCESS;
    }
}

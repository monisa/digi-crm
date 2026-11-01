<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;

/** Round-robin assignment of new leads among the people chosen by the admin. */
class LeadAssigner
{
    public function __construct(private LeadOptions $options) {}

    public function autoAssign(Lead $lead): void
    {
        $opts = $this->options->all();
        if (! $opts['auto_assign'] || $lead->assigned_to) {
            return;
        }

        $pool = User::whereIn('id', $opts['assign_pool'] ?? [])->where('status', 'active')->orderBy('id')->pluck('id')->all();
        if (! $pool) {
            return;
        }

        $last = Setting::get('lead_assign_last');
        $pos = array_search($last, $pool, true);
        $next = $pool[$pos === false ? 0 : ($pos + 1) % count($pool)];
        Setting::put('lead_assign_last', $next);

        $lead->update(['assigned_to' => $next]);
        $lead->log(null, 'assignment', 'Assigned automatically to '.User::find($next)?->name);
    }
}

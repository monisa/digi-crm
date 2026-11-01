<?php

namespace App\Services\Leads;

use App\Models\Setting;

/** Dropdown choices for leads (call status, package…) and auto-assign settings. */
class LeadOptions
{
    public function all(): array
    {
        return [
            'statuses' => config('crm.lead_statuses'),
            ...config('crm.lead_options'),
            ...Setting::get('lead_options', []),
        ];
    }

    public function save(array $values): array
    {
        Setting::put('lead_options', $values);

        return $this->all();
    }

    /** Map a status written by a person (e.g. "Interested", "converted") to a pipeline key. */
    public function statusKey(?string $text): ?string
    {
        $t = strtolower(trim((string) $text));
        if ($t === '') {
            return null;
        }
        foreach (config('crm.lead_statuses') as $key => $label) {
            if ($t === $key || $t === strtolower($label)) {
                return $key;
            }
        }
        $aliases = [
            'won' => ['converted', 'closed', 'closed won', 'deal closed', 'onboarded', 'confirmed'],
            'lost' => ['not interested', 'lost', 'rejected', 'junk', 'spam', 'dead'],
            'proposal' => ['proposal', 'quotation sent', 'quote sent', 'proposal shared'],
            'qualified' => ['interested', 'hot', 'warm', 'qualified', 'positive'],
            'contacted' => ['contacted', 'follow up', 'follow-up', 'followup', 'in progress', 'cold'],
            'new' => ['new', 'fresh', 'not contacted'],
        ];
        foreach ($aliases as $key => $words) {
            if (in_array($t, $words, true)) {
                return $key;
            }
        }

        return null;
    }
}

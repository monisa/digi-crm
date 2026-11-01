<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\MetaPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Meta (Facebook / Instagram) Lead Ads.
 *
 * 1. Meta calls our webhook with a leadgen_id when someone submits a lead form.
 * 2. We fetch the lead from the Graph API with the Page token and save it.
 * 3. A poll every 15 minutes fetches recent leads per form, in case a webhook was missed.
 */
class MetaLeads
{
    public function __construct(private LeadAssigner $assigner, private LeadImporter $importer) {}

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version').'/'.ltrim($path, '/');
    }

    /** Webhook signature check (X-Hub-Signature-256 = sha256=HMAC(body, app secret)). */
    public function validSignature(string $body, ?string $header): bool
    {
        $secret = config('services.meta.app_secret');
        if (! $secret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $header);
    }

    /** Check a Page token and return the Page name. */
    public function pageName(string $pageId, string $token): string
    {
        try {
            $res = Http::timeout(15)->get($this->graph($pageId), ['fields' => 'name', 'access_token' => $token]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Meta from the server. Please try again in a minute.');
        }
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error.message') ?? 'Meta did not accept this Page ID / token.');
        }

        return (string) $res->json('name');
    }

    /** Ask Meta to send this Page's new leads to our webhook. */
    public function subscribe(MetaPage $page): void
    {
        try {
            $res = Http::timeout(15)->asForm()->post($this->graph($page->page_id.'/subscribed_apps'), [
                'subscribed_fields' => 'leadgen',
                'access_token' => $page->access_token,
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Meta from the server.');
        }
        if (! $res->successful() || ! $res->json('success')) {
            throw new RuntimeException($res->json('error.message') ?? 'Subscribing the Page failed.');
        }
        $page->update(['subscribed' => true, 'last_error' => null]);
    }

    /** Fetch one lead by ID and save it (does nothing if we already have it). */
    public function import(string $leadgenId, MetaPage $page): ?Lead
    {
        if ($existing = Lead::where('meta_leadgen_id', $leadgenId)->first()) {
            return $existing;
        }

        $res = Http::timeout(20)->get($this->graph($leadgenId), [
            'fields' => 'created_time,field_data,ad_name,adset_name,campaign_name,form_id,platform',
            'access_token' => $page->access_token,
        ]);
        if (! $res->successful()) {
            throw new RuntimeException($res->json('error.message') ?? "Could not fetch lead $leadgenId");
        }

        return $this->store($res->json());
    }

    /** Fetch recent leads for every form on every connected Page. */
    public function poll(?int $hoursBack = null): int
    {
        $created = 0;
        foreach (MetaPage::all() as $page) {
            try {
                $since = ($page->last_polled_at ?? now()->subHours($hoursBack ?? 24))->subMinutes(30)->timestamp;
                $forms = Http::timeout(20)->get($this->graph($page->page_id.'/leadgen_forms'), [
                    'fields' => 'id,name', 'limit' => 100, 'access_token' => $page->access_token,
                ])->throw()->json('data', []);

                foreach ($forms as $form) {
                    $url = $this->graph($form['id'].'/leads');
                    $params = [
                        'fields' => 'created_time,field_data,ad_name,adset_name,campaign_name,form_id,platform',
                        'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since]]),
                        'limit' => 100,
                        'access_token' => $page->access_token,
                    ];
                    do {
                        $res = Http::timeout(20)->get($url, $params)->throw();
                        foreach ($res->json('data', []) as $row) {
                            if (! Lead::where('meta_leadgen_id', $row['id'])->exists()) {
                                $this->store($row + ['form_name' => $form['name'] ?? null]);
                                $created++;
                            }
                        }
                        $url = $res->json('paging.next');
                        $params = [];
                    } while ($url);
                }
                $page->update(['last_polled_at' => now(), 'last_error' => null]);
            } catch (\Throwable $e) {
                Log::warning('Meta poll failed for page '.$page->page_id.': '.$e->getMessage());
                $page->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }

        return $created;
    }

    /** Turn a Graph API lead into a CRM lead. */
    public function store(array $m): Lead
    {
        $fields = [];
        foreach ($m['field_data'] ?? [] as $f) {
            $fields[strtolower($f['name'])] = implode(', ', (array) ($f['values'] ?? []));
        }
        $take = function (array $keys) use (&$fields): ?string {
            foreach ($keys as $k) {
                if (isset($fields[$k]) && $fields[$k] !== '') {
                    $v = $fields[$k];
                    unset($fields[$k]);

                    return $v;
                }
            }

            return null;
        };

        $name = $take(['full_name', 'name']) ?? trim(($take(['first_name']) ?? '').' '.($take(['last_name']) ?? ''));
        $phone = $take(['phone_number', 'phone', 'mobile_number', 'whatsapp_number']);
        $email = $take(['email', 'work_email']);
        $city = $take(['city', 'location', 'district']);
        $package = $take(['package', 'which_package', 'budget']);

        // Remaining answers are the custom questions, e.g. "what service do you need?"
        $requirement = $fields ? collect($fields)->map(fn ($v, $k) => ucfirst(str_replace('_', ' ', trim($k, '?'))).': '.$v)->implode("\n") : null;

        $lead = Lead::create([
            'meta_leadgen_id' => $m['id'],
            'meta_form_id' => $m['form_id'] ?? null,
            'form_name' => $m['form_name'] ?? null,
            'full_name' => $name !== '' ? $name : 'Meta lead',
            'phone' => $phone ? $this->importer->phone($phone) : null,
            'email' => $email ? strtolower($email) : null,
            'city' => $city,
            'package' => $package,
            'requirement' => $requirement,
            'custom_fields' => $fields ?: null,
            'ad_name' => $m['ad_name'] ?? null,
            'adset_name' => $m['adset_name'] ?? null,
            'campaign_name' => $m['campaign_name'] ?? null,
            'platform' => match ($m['platform'] ?? null) { 'ig' => 'instagram', 'fb' => 'facebook', default => $m['platform'] ?? null },
            'source' => 'meta',
            'status' => 'new',
            'call_status' => 'Not called',
            'raw_payload' => $m,
            'created_at' => isset($m['created_time']) ? CarbonImmutable::parse($m['created_time'])->setTimezone(config('app.timezone')) : now(),
        ]);

        $lead->log(null, 'system', 'Lead received from '.ucfirst($lead->platform ?? 'Meta').($lead->ad_name ? ' ad "'.$lead->ad_name.'"' : ''));
        $this->assigner->autoAssign($lead);

        return $lead;
    }
}

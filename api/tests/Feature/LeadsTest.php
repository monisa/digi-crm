<?php

namespace Tests\Feature;

use App\Jobs\ImportMetaLead;
use App\Models\Lead;
use App\Models\MetaPage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Leads\MetaLeads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-02 10:00:00');
        $this->admin = User::factory()->admin()->create();
        config(['services.meta.app_secret' => 'app-secret', 'services.meta.verify_token' => 'verify-me', 'services.meta.graph_version' => 'v21.0']);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function staff(string $scope = 'assigned'): User
    {
        $u = User::factory()->create(['leads_scope' => $scope]);
        // Employees need leads permissions (not in their role defaults).
        $this->as($this->admin)->putJson("/v1/admin/users/{$u->id}/permissions", ['overrides' => ['leads.view' => true, 'leads.edit' => true, 'leads.create' => true]]);

        return $u;
    }

    private function metaLead(array $extra = []): array
    {
        return [
            'id' => '1203456789',
            'created_time' => '2026-11-02T04:00:00+0000',
            'ad_name' => 'Diwali Offer - Video',
            'adset_name' => 'Kochi 25-45',
            'campaign_name' => 'Diwali 2026',
            'form_id' => '555',
            'platform' => 'ig',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Anu Thomas']],
                ['name' => 'phone_number', 'values' => ['+919847012345']],
                ['name' => 'email', 'values' => ['Anu@Example.com']],
                ['name' => 'city', 'values' => ['Kochi']],
                ['name' => 'which_service_do_you_need?', 'values' => ['SEO + Meta ads']],
            ],
            ...$extra,
        ];
    }

    // ---------- Meta ----------

    public function test_webhook_verification_handshake(): void
    {
        $this->get('/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')->assertOk()->assertSeeText('12345');
        $this->get('/v1/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
    }

    public function test_webhook_rejects_bad_signature_and_queues_valid_leads(): void
    {
        Queue::fake();
        $body = json_encode(['object' => 'page', 'entry' => [['id' => '102938475610', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '1203456789', 'page_id' => '102938475610']]]]]]);

        $this->call('POST', '/v1/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertForbidden();
        Queue::assertNothingPushed();

        $sig = 'sha256='.hash_hmac('sha256', $body, 'app-secret');
        $this->call('POST', '/v1/webhooks/meta', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $body)->assertOk();
        Queue::assertPushed(ImportMetaLead::class, fn ($j) => $j->leadgenId === '1203456789' && $j->pageId === '102938475610');
    }

    public function test_meta_lead_is_fetched_mapped_and_not_duplicated(): void
    {
        $page = MetaPage::create(['page_id' => '102938475610', 'name' => 'DigiFox', 'access_token' => 'page-token-xyz']);
        Http::fake(['graph.facebook.com/v21.0/1203456789*' => Http::response($this->metaLead())]);

        (new ImportMetaLead('1203456789', '102938475610'))->handle(app(MetaLeads::class));
        (new ImportMetaLead('1203456789', '102938475610'))->handle(app(MetaLeads::class));

        $this->assertSame(1, Lead::count());
        $lead = Lead::first();
        $this->assertSame(['Anu Thomas', '+91 98470 12345', 'anu@example.com', 'Kochi', 'instagram', 'Diwali Offer - Video', 'meta', 'new', 'Not called'],
            [$lead->full_name, $lead->phone, $lead->email, $lead->city, $lead->platform, $lead->ad_name, $lead->source, $lead->status, $lead->call_status]);
        $this->assertSame('Which service do you need: SEO + Meta ads', $lead->requirement);
        $this->assertSame('2026-11-02 09:30', $lead->created_at->format('Y-m-d H:i')); // stored in IST
        $this->assertSame('page-token-xyz', $page->fresh()->access_token);
        $this->assertNotSame('page-token-xyz', \DB::table('meta_pages')->value('access_token')); // encrypted at rest
    }

    public function test_poll_imports_missed_leads(): void
    {
        MetaPage::create(['page_id' => '102938475610', 'name' => 'DigiFox', 'access_token' => 'tok-1234567890']);
        Http::fake([
            'graph.facebook.com/v21.0/102938475610/leadgen_forms*' => Http::response(['data' => [['id' => '555', 'name' => 'Diwali form']]]),
            'graph.facebook.com/v21.0/555/leads*' => Http::response(['data' => [$this->metaLead(), $this->metaLead(['id' => '999'])]]),
        ]);

        $this->artisan('meta:poll')->assertSuccessful();
        $this->assertSame(2, Lead::count());
        $this->assertSame('Diwali form', Lead::first()->form_name);
        $this->assertNotNull(MetaPage::first()->last_polled_at);
    }

    public function test_admin_connects_page_with_token_check(): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/102938475610?*' => Http::response(['name' => 'DigiFox Pro Solutions', 'id' => '102938475610']),
            'graph.facebook.com/v21.0/102938475610/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/v21.0/203948576120?*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
        ]);

        $this->as($this->admin)->postJson('/v1/integrations/meta/pages', ['page_id' => '102938475610', 'access_token' => str_repeat('a', 40)])
            ->assertCreated()->assertJsonPath('name', 'DigiFox Pro Solutions')->assertJsonPath('subscribed', true)->assertJsonMissing(['access_token']);
        $this->postJson('/v1/integrations/meta/pages', ['page_id' => '203948576120', 'access_token' => str_repeat('b', 40)])
            ->assertUnprocessable()->assertJsonPath('errors.access_token.0', 'Meta said: Invalid OAuth access token.');
    }

    public function test_meta_unreachable_gives_a_clear_message(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->as($this->admin)->postJson('/v1/integrations/meta/pages', ['page_id' => '102938475610', 'access_token' => str_repeat('a', 40)])
            ->assertUnprocessable()->assertJsonPath('errors.access_token.0', 'Meta said: Could not reach Meta from the server. Please try again in a minute.');
    }

    public function test_round_robin_auto_assignment(): void
    {
        $a = $this->staff();
        $b = $this->staff();
        Setting::put('lead_options', ['auto_assign' => true, 'assign_pool' => [$a->id, $b->id]]);
        $meta = app(MetaLeads::class);

        $ids = collect(['1', '2', '3'])->map(fn ($id) => $meta->store($this->metaLead(['id' => $id]))->fresh()->assigned_to)->all();

        $this->assertSame([$a->id, $b->id, $a->id], $ids);
    }

    // ---------- Import from the lead sheet ----------

    public function test_csv_import_uses_sheet_columns_and_skips_duplicates(): void
    {
        Lead::create(['full_name' => 'Existing', 'phone' => '+91 98470 00000']);
        $csv = "\xEF\xBB\xBFDate,AD Name,Name,Phone Number,Email ID,Location (City),Call Status,Status ,Package ,Requirement,Follow-up Status,Notes / Remarks\n"
            ."01/11/2026,Diwali Offer,Rahul Kumar,9847011111,rahul@x.com,Kochi,Connected,Interested,Growth,SEO for clinic,Call on 05/11/2026,Asked for quote\n"
            ."31/10/2026,Brand Launch,Sneha Menon,+91 98470 22222,,Thrissur,Not answering,Hot lead?,,Website,,\n"
            ."30/10/2026,Brand Launch,Dup Person,98470 00000,,Kochi,,,,,,\n"
            .",,,,,,,,,,,\n"
            ."29/10/2026,X,No Contact,,,Kochi,,,,,,\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $res = $this->as($this->admin)->post('/v1/leads/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([2, 1], [$res->json('created'), $res->json('duplicates')]);
        $this->assertCount(1, $res->json('errors'));

        $rahul = Lead::where('full_name', 'Rahul Kumar')->first();
        $this->assertSame(['+91 98470 11111', 'qualified', 'Connected', 'Growth', '2026-11-01', '2026-11-05', 'import'],
            [$rahul->phone, $rahul->status, $rahul->call_status, $rahul->package, $rahul->created_at->toDateString(), $rahul->next_followup_at->toDateString(), $rahul->source]);

        // Unknown status text is kept in the notes, and a called lead counts as contacted.
        $sneha = Lead::where('full_name', 'Sneha Menon')->first();
        $this->assertSame('contacted', $sneha->status);
        $this->assertStringContainsString('Status in sheet: Hot lead?', $sneha->notes);
    }

    public function test_export_has_the_sheet_columns(): void
    {
        Lead::create(['full_name' => 'Rahul', 'phone' => '+91 98470 11111', 'status' => 'qualified', 'call_status' => 'Connected']);
        $csv = $this->as($this->admin)->get('/v1/leads/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Date,Service,Name,"Phone Number","Email ID","Location (City)","Call Status"', $csv);
        $this->assertStringContainsString('Rahul,"+91 98470 11111",,,Connected,Interested', $csv);
    }

    // ---------- Access ----------

    public function test_staff_only_see_their_assigned_leads_unless_given_all(): void
    {
        $priya = $this->staff();
        $arjun = $this->staff();
        $mine = Lead::create(['full_name' => 'Mine', 'assigned_to' => $priya->id]);
        $theirs = Lead::create(['full_name' => 'Theirs', 'assigned_to' => $arjun->id]);
        Lead::create(['full_name' => 'Nobody']);

        $this->as($priya)->getJson('/v1/leads')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.full_name', 'Mine');
        $this->getJson("/v1/leads/{$theirs->id}")->assertForbidden();
        $this->patchJson("/v1/leads/{$theirs->id}", ['status' => 'won'])->assertForbidden();
        $this->postJson('/v1/leads/assign', ['ids' => [$theirs->id], 'assigned_to' => $priya->id])->assertForbidden();

        $this->as($this->admin)->patchJson("/v1/admin/users/{$priya->id}", ['leads_scope' => 'all'])->assertOk();
        $this->as($priya->fresh())->getJson('/v1/leads')->assertJsonPath('meta.total', 3);

        // Leads module is off for employees by default
        $plain = User::factory()->create();
        $this->as($plain)->getJson('/v1/leads')->assertForbidden();
    }

    public function test_logging_a_call_marks_the_lead_contacted(): void
    {
        $lead = Lead::create(['full_name' => 'Rahul', 'status' => 'new', 'call_status' => 'Not called', 'created_at' => now()->subHours(2)]);
        $this->as($this->admin)->getJson('/v1/leads?view=uncontacted')->assertJsonPath('meta.total', 1);

        $this->postJson("/v1/leads/{$lead->id}/activities", ['type' => 'call', 'outcome' => 'Connected', 'note' => 'Wants SEO quote', 'next_followup_at' => '2026-11-05 11:00', 'followup_status' => 'Follow-up scheduled'])
            ->assertCreated()->assertJsonPath('status', 'contacted')->assertJsonPath('call_status', 'Connected')
            ->assertJsonPath('activities.0.note', 'Wants SEO quote');

        $this->getJson('/v1/leads?view=uncontacted')->assertJsonPath('meta.total', 0);
        $this->assertNotNull($lead->fresh()->first_contacted_at);
        $this->postJson("/v1/leads/{$lead->id}/activities", ['type' => 'note'])->assertUnprocessable();
    }

    public function test_stage_change_is_logged_and_lead_converts_to_client(): void
    {
        $lead = Lead::create(['full_name' => 'Rahul', 'phone' => '+91 98470 11111', 'email' => 'r@x.com']);

        $this->as($this->admin)->patchJson("/v1/leads/{$lead->id}", ['status' => 'proposal'])->assertOk()
            ->assertJsonPath('activities.0.note', 'Stage changed to Proposal sent');

        $res = $this->postJson("/v1/leads/{$lead->id}/convert", ['company_name' => 'Rahul Clinic'])->assertOk();
        $this->assertDatabaseHas('clients', ['id' => $res->json('client_id'), 'company_name' => 'Rahul Clinic', 'phone' => '+91 98470 11111']);
        $this->assertSame('won', $lead->fresh()->status);
        $this->postJson("/v1/leads/{$lead->id}/convert", ['company_name' => 'Again'])->assertUnprocessable();
    }

    public function test_search_finds_phone_numbers_typed_any_way(): void
    {
        Lead::create(['full_name' => 'Rahul', 'phone' => '+91 98470 11111']);

        foreach (['9847011111', '98470 11111', '+919847011111', 'rahul'] as $q) {
            $this->as($this->admin)->getJson('/v1/leads?search='.urlencode($q))->assertJsonPath('meta.total', 1);
        }
    }

    public function test_admin_edits_dropdown_options(): void
    {
        $this->as($this->admin)->putJson('/v1/leads/options', [
            'call_statuses' => ['Not called', 'Connected'], 'packages' => ['Basic'], 'followup_statuses' => ['Scheduled'],
            'lost_reasons' => ['Budget'], 'auto_assign' => false, 'assign_pool' => [],
        ])->assertOk()->assertJsonPath('packages', ['Basic']);

        $this->getJson('/v1/leads/options')->assertJsonPath('statuses.qualified', 'Interested')->assertJsonPath('packages', ['Basic']);
    }

    public function test_bulk_delete_ticked_or_all_matching(): void
    {
        $a = Lead::create(['full_name' => 'A', 'status' => 'new']);
        $b = Lead::create(['full_name' => 'B', 'status' => 'lost']);
        $c = Lead::create(['full_name' => 'C', 'status' => 'lost']);
        $d = Lead::create(['full_name' => 'D', 'status' => 'new']);
        $a->log($this->admin, 'note', 'hello');

        $this->as($this->admin)->postJson('/v1/leads/bulk-delete', ['ids' => [$a->id]])->assertOk()->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('lead_activities', ['lead_id' => $a->id]);

        // "All matching": only the filtered ones go.
        $this->postJson('/v1/leads/bulk-delete', ['all' => true, 'status' => 'lost'])->assertOk()->assertJsonPath('deleted', 2);
        $this->assertSame(['D'], Lead::pluck('full_name')->all());
        $this->postJson('/v1/leads/bulk-delete', [])->assertStatus(422);
    }

    public function test_bulk_delete_needs_permission_and_only_touches_own_leads(): void
    {
        $staff = $this->staff();
        $mine = Lead::create(['full_name' => 'Mine', 'assigned_to' => $staff->id]);
        $other = Lead::create(['full_name' => 'Other']);

        $this->as($staff)->postJson('/v1/leads/bulk-delete', ['all' => true])->assertForbidden();

        $this->as($this->admin)->putJson("/v1/admin/users/{$staff->id}/permissions", ['overrides' => ['leads.view' => true, 'leads.delete' => true]]);
        $this->as($staff->fresh())->postJson('/v1/leads/bulk-delete', ['ids' => [$mine->id, $other->id]])->assertOk()->assertJsonPath('deleted', 1);
        $this->assertModelExists($other);
        $this->assertModelMissing($mine);
    }

    public function test_filter_by_ad_and_date(): void
    {
        Lead::create(['full_name' => 'A', 'ad_name' => 'Course', 'created_at' => '2026-10-13 10:00:00']);
        Lead::create(['full_name' => 'B', 'ad_name' => 'Course', 'created_at' => '2026-11-04 10:00:00']);
        Lead::create(['full_name' => 'C', 'ad_name' => 'SEO offer', 'created_at' => '2026-11-04 11:00:00']);
        Lead::create(['full_name' => 'D', 'created_at' => '2026-11-04 12:00:00']);

        $r = $this->as($this->admin)->getJson('/v1/leads?ad=Course')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEquals(['Course' => 2, 'SEO offer' => 1], $r->json('ads'));
        $this->getJson('/v1/leads?ad=Course&from=2026-11-01&to=2026-11-30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'B');
        $this->getJson('/v1/leads?ad=__none')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'D');
    }

    public function test_sorting_by_date_service_and_assignee(): void
    {
        $zara = User::factory()->create(['name' => 'Zara', 'role' => 'employee']);
        $anu = User::factory()->create(['name' => 'Anu', 'role' => 'employee']);
        Lead::create(['full_name' => 'Old', 'ad_name' => 'SEO', 'assigned_to' => $zara->id, 'created_at' => '2026-10-01 10:00']);
        Lead::create(['full_name' => 'Mid', 'ad_name' => 'Course', 'created_at' => '2026-10-15 10:00']);
        Lead::create(['full_name' => 'New', 'assigned_to' => $anu->id, 'created_at' => '2026-11-04 10:00']);
        $names = fn ($q) => collect($this->getJson('/v1/leads'.$q)->assertOk()->json('data'))->pluck('full_name')->all();

        $this->as($this->admin);
        $this->assertSame(['New', 'Mid', 'Old'], $names(''));
        $this->assertSame(['Old', 'Mid', 'New'], $names('?sort=date&dir=asc'));
        $this->assertSame(['Mid', 'Old', 'New'], $names('?sort=service'));          // Course, SEO, then empty
        $this->assertSame(['Old', 'Mid', 'New'], $names('?sort=service&dir=desc'));
        $this->assertSame(['New', 'Old', 'Mid'], $names('?sort=assigned'));         // Anu, Zara, then unassigned
    }
}

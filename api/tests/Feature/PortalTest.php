<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    private Client $mine;

    private Client $other;

    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-02 10:00:00');
        $this->admin = User::factory()->admin()->create(['name' => 'Amit']);
        $this->mine = Client::create(['company_name' => 'Green Leaf', 'state' => 'Kerala', 'account_manager_id' => $this->admin->id]);
        $this->other = Client::create(['company_name' => 'Other Co', 'state' => 'Kerala']);
        $this->login = User::factory()->client()->create(['client_id' => $this->mine->id, 'name' => 'Asha']);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function campaign(Client $c, array $extra = []): array
    {
        return $this->as($this->admin)->postJson('/v1/campaigns', [
            'client_id' => $c->id, 'name' => 'Diwali leads', 'platform' => 'meta', 'budget' => 30000, 'start_date' => '2026-10-01', 'status' => 'active', ...$extra,
        ])->assertCreated()->json();
    }

    public function test_staff_record_results_and_clients_see_only_their_campaigns(): void
    {
        $c = $this->campaign($this->mine);
        $this->campaign($this->other, ['name' => 'Not yours']);

        $this->putJson("/v1/campaigns/{$c['id']}", ['spend' => 24000, 'impressions' => 200000, 'clicks' => 3000, 'leads' => 120, 'summary' => 'Video ads did best.'])
            ->assertOk()->assertJsonPath('results.cost_per_lead', 200)->assertJsonPath('results.ctr', 1.5);

        $this->as($this->login)->getJson('/v1/campaigns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.results.leads', 120);
        $other = Campaign::where('client_id', $this->other->id)->first();
        $this->getJson("/v1/campaigns/{$other->id}")->assertNotFound();
        $this->putJson("/v1/campaigns/{$c['id']}", ['leads' => 99999])->assertForbidden();
        $this->postJson('/v1/campaigns', ['client_id' => $this->mine->id, 'name' => 'x', 'platform' => 'meta'])->assertForbidden();
    }

    public function test_report_files_are_private_to_the_client(): void
    {
        Storage::fake('local');
        $c = $this->campaign($this->mine);
        $res = $this->post("/v1/campaigns/{$c['id']}/files", ['file' => UploadedFile::fake()->create('October report.pdf', 120, 'application/pdf'), 'title' => 'October report'], ['Accept' => 'application/json'])
            ->assertCreated();
        $fileId = $res->json('files.0.id');
        $this->post("/v1/campaigns/{$c['id']}/files", ['file' => UploadedFile::fake()->create('x.php', 1, 'text/x-php')], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->as($this->login)->get("/v1/campaign-files/{$fileId}")->assertOk();
        $stranger = User::factory()->client()->create(['client_id' => $this->other->id]);
        $this->as($stranger)->get("/v1/campaign-files/{$fileId}", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_ticket_conversation_with_internal_notes(): void
    {
        $t = $this->as($this->login)->postJson('/v1/tickets', ['subject' => 'Change ad creative', 'category' => 'Design request', 'body' => 'Please use the new Diwali poster.'])
            ->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('assigned_to.name', 'Amit')->assertJsonPath('awaiting_staff', true)->json();
        // Clients cannot pick another company
        $this->postJson('/v1/tickets', ['client_id' => $this->other->id, 'subject' => 'x', 'body' => 'y'])->assertUnprocessable();

        $this->as($this->admin)->postJson("/v1/tickets/{$t['id']}/messages", ['body' => 'Client is slow to pay, keep it short.', 'internal' => true])->assertCreated();
        $this->postJson("/v1/tickets/{$t['id']}/messages", ['body' => 'Done! New poster is live.'])->assertCreated()
            ->assertJsonPath('status', 'in_progress')->assertJsonPath('awaiting_staff', false);
        $this->patchJson("/v1/tickets/{$t['id']}", ['status' => 'resolved'])->assertOk();

        $seen = $this->as($this->login)->getJson("/v1/tickets/{$t['id']}")->assertOk()->json('messages');
        $this->assertSame(['Please use the new Diwali poster.', 'Done! New poster is live.'], array_column($seen, 'body'));

        // Client writes back → reopened and waiting for staff
        $this->postJson("/v1/tickets/{$t['id']}/messages", ['body' => 'Small change please.', 'internal' => true])
            ->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('awaiting_staff', true);
        $this->assertFalse(Ticket::first()->messages()->latest('id')->first()->internal); // clients cannot post internal notes

        // Clients can close but not set other statuses or reassign
        $this->patchJson("/v1/tickets/{$t['id']}", ['status' => 'in_progress'])->assertUnprocessable();
        $this->patchJson("/v1/tickets/{$t['id']}", ['status' => 'closed'])->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_clients_and_staff_only_see_tickets_they_should(): void
    {
        $theirs = Ticket::create(['client_id' => $this->other->id, 'subject' => 'Other', 'status' => 'open']);
        $this->as($this->login)->getJson('/v1/tickets')->assertJsonCount(0, 'data');
        $this->getJson("/v1/tickets/{$theirs->id}")->assertNotFound();

        $priya = User::factory()->create();
        $this->as($this->admin)->putJson("/v1/admin/users/{$priya->id}/permissions", ['overrides' => ['tickets.view' => true]]);
        $this->as($priya->fresh())->getJson('/v1/tickets')->assertJsonCount(0, 'data'); // manages no clients
        $this->as($this->admin)->getJson('/v1/tickets')->assertJsonCount(1, 'data')->assertJsonPath('counts.needs_reply', 1);
    }

    public function test_client_dashboard(): void
    {
        $this->campaign($this->mine, ['leads' => 40, 'spend' => 10000]);
        $this->campaign($this->mine, ['name' => 'Planned', 'status' => 'planned']);
        Invoice::create(['client_id' => $this->mine->id, 'invoice_no' => 'DFP/2026-27/001', 'issue_date' => '2026-10-01', 'due_date' => '2026-10-16', 'subtotal' => 10000, 'tax' => 1800, 'total' => 11800, 'amount_paid' => 1800, 'status' => 'partial']);
        Invoice::create(['client_id' => $this->mine->id, 'invoice_no' => 'DFP/2026-27/002', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-16', 'subtotal' => 5000, 'tax' => 900, 'total' => 5900, 'status' => 'unpaid']);
        Ticket::create(['client_id' => $this->mine->id, 'subject' => 'Q', 'status' => 'in_progress', 'awaiting_staff' => false]);

        $this->as($this->login)->getJson('/v1/dashboard/client')->assertOk()
            ->assertJsonPath('company', 'Green Leaf')
            ->assertJsonPath('account_manager.name', 'Amit')
            ->assertJsonPath('billing.to_pay', 15900)
            ->assertJsonPath('billing.overdue', 1)
            ->assertJsonPath('billing.next.invoice_no', 'DFP/2026-27/001')
            ->assertJsonPath('totals.active_campaigns', 1)
            ->assertJsonPath('totals.leads', 40)
            ->assertJsonCount(2, 'campaigns')
            ->assertJsonPath('tickets.replied', 1);

        $this->as($this->admin)->getJson('/v1/dashboard/client')->assertNotFound();
    }
}

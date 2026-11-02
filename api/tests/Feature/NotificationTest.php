<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\CrmAlert;
use App\Services\Leads\MetaLeads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-02 10:00:00'); // Monday
        $this->admin = User::factory()->admin()->create(['name' => 'Amit']);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function sentTypes(User $u): array
    {
        return $u->fresh()->notifications->pluck('data.type')->sort()->values()->all();
    }

    public function test_bell_lists_counts_and_marks_read(): void
    {
        $this->admin->notify(new CrmAlert('lead_new', 'New lead: Anu', 'From the ad', '/leads?open=1'));
        $this->admin->notify(new CrmAlert('ticket_new', 'New request', 'Help', '/tickets/1'));

        $this->as($this->admin)->getJson('/v1/notifications/unread-count')->assertJsonPath('unread', 2);
        $list = $this->getJson('/v1/notifications?category=leads')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'New lead: Anu')->assertJsonPath('data.0.link', '/leads?open=1')->json('data');
        $this->postJson('/v1/notifications/read', ['ids' => [$list[0]['id']]])->assertJsonPath('unread', 1);
        $this->postJson('/v1/notifications/read', ['all' => true])->assertJsonPath('unread', 0);

        // Nobody sees someone else's notifications
        $this->as(User::factory()->create())->getJson('/v1/notifications')->assertJsonCount(0, 'data');
    }

    public function test_meta_lead_alerts_assignee_or_admins(): void
    {
        app(MetaLeads::class)->store(['id' => '1', 'field_data' => [['name' => 'full_name', 'values' => ['Anu']]]]);
        $this->assertSame(['lead_new'], $this->sentTypes($this->admin));
    }

    public function test_uncontacted_lead_alert_is_sent_once_with_email(): void
    {
        Notification::fake();
        $priya = User::factory()->create();
        Lead::create(['full_name' => 'Late', 'status' => 'new', 'assigned_to' => $priya->id, 'created_at' => now()->subMinutes(45)]);
        Lead::create(['full_name' => 'Fresh', 'status' => 'new', 'created_at' => now()->subMinutes(5)]);
        Lead::create(['full_name' => 'Old import', 'status' => 'new', 'created_at' => now()->subDays(20)]);

        $this->artisan('crm:lead-sla')->assertSuccessful();
        $this->artisan('crm:lead-sla')->assertSuccessful();

        Notification::assertSentToTimes($priya, CrmAlert::class, 1);
        Notification::assertSentTo($this->admin, CrmAlert::class, fn (CrmAlert $n, array $ch) => $n->type === 'lead_uncontacted' && in_array('mail', $ch, true) && str_contains($n->body, '45 min'));
        Notification::assertCount(2);
    }

    public function test_email_can_be_switched_off_per_type(): void
    {
        $this->as($this->admin)->putJson('/v1/notifications/preferences', ['email' => ['lead_uncontacted' => false]])
            ->assertOk()->assertJsonFragment(['key' => 'lead_uncontacted', 'email' => false]);

        $alert = new CrmAlert('lead_uncontacted', 't', 'b', '/', true);
        $this->assertSame(['database'], $alert->via($this->admin->fresh()));
        $this->assertSame(['database', 'mail'], (new CrmAlert('ticket_new', 't', 'b', '/', true))->via($this->admin->fresh()));

        // Clients only see client notification types
        $client = User::factory()->client()->create(['client_id' => Client::create(['company_name' => 'X'])->id]);
        $keys = collect($this->as($client)->getJson('/v1/notifications/preferences')->json('types'))->pluck('key')->all();
        $this->assertSame(['ticket_update', 'payment_decided', 'invoice_issued', 'invoice_overdue'], $keys);
    }

    public function test_ticket_conversation_notifies_the_other_side_but_not_for_internal_notes(): void
    {
        $manager = User::factory()->create();
        $client = Client::create(['company_name' => 'Green Leaf', 'account_manager_id' => $manager->id]);
        $login = User::factory()->client()->create(['client_id' => $client->id]);

        $t = $this->as($login)->postJson('/v1/tickets', ['subject' => 'Ads down', 'priority' => 'high', 'body' => 'Help'])->json();
        $this->assertSame(['ticket_new'], $this->sentTypes($manager));
        $this->assertSame('Urgent request: Ads down', $manager->fresh()->notifications->first()->data['title']);

        $this->as($this->admin)->postJson("/v1/tickets/{$t['id']}/messages", ['body' => 'internal', 'internal' => true]);
        $this->assertSame([], $this->sentTypes($login));
        $this->postJson("/v1/tickets/{$t['id']}/messages", ['body' => 'Fixed now']);
        $this->assertSame(['ticket_update'], $this->sentTypes($login));

        $this->as($login)->postJson("/v1/tickets/{$t['id']}/messages", ['body' => 'Thanks!']);
        $this->assertSame(['ticket_new', 'ticket_reply'], $this->sentTypes($manager));
    }

    public function test_leave_request_and_decision(): void
    {
        $user = User::factory()->create(['name' => 'Priya']);
        Employee::create(['emp_code' => 'E1', 'name' => 'Priya', 'user_id' => $user->id]);
        $id = $this->as($user)->postJson('/v1/leave/requests', ['leave_type_id' => LeaveType::where('code', 'CL')->value('id'), 'from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'reason' => 'Family'])->json('id');

        $this->assertSame(['leave_requested'], $this->sentTypes($this->admin));
        $this->assertSame([], $this->sentTypes($user)); // not told about their own request

        $this->as($this->admin)->postJson("/v1/leave/requests/{$id}/approve");
        $this->assertSame(['leave_decided'], $this->sentTypes($user));
        $this->assertSame('Your leave was approved', $user->fresh()->notifications->first()->data['title']);
    }

    public function test_payment_claim_and_confirmation(): void
    {
        $client = Client::create(['company_name' => 'Green Leaf', 'state' => 'Kerala']);
        $login = User::factory()->client()->create(['client_id' => $client->id]);
        $inv = Invoice::create(['client_id' => $client->id, 'invoice_no' => 'DFP/2026-27/001', 'issue_date' => '2026-10-20', 'due_date' => '2026-11-04', 'subtotal' => 1000, 'total' => 1180, 'status' => 'unpaid']);

        $claim = $this->as($login)->postJson("/v1/invoices/{$inv->id}/claims", ['amount' => 1180, 'paid_on' => '2026-11-02', 'mode' => 'upi', 'reference' => '630512345678'])->json();
        $this->assertSame(['payment_claim'], $this->sentTypes($this->admin));

        $this->as($this->admin)->postJson("/v1/payment-claims/{$claim['id']}/confirm");
        $this->assertSame(['payment_decided'], $this->sentTypes($login));
        $this->assertSame('Payment received, thank you!', $login->fresh()->notifications->first()->data['title']);
    }

    public function test_overdue_reminders_weekly_up_to_four(): void
    {
        Notification::fake();
        $withLogin = Client::create(['company_name' => 'A']);
        $login = User::factory()->client()->create(['client_id' => $withLogin->id]);
        $emailOnly = Client::create(['company_name' => 'B', 'email' => 'accounts@b.test']);
        foreach ([[$withLogin, '001'], [$emailOnly, '002']] as [$c, $n]) {
            Invoice::create(['client_id' => $c->id, 'invoice_no' => "DFP/2026-27/$n", 'issue_date' => '2026-10-01', 'due_date' => '2026-10-16', 'subtotal' => 1000, 'total' => 1180, 'status' => 'unpaid']);
        }
        Invoice::create(['client_id' => $withLogin->id, 'invoice_no' => 'DFP/2026-27/003', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-16', 'subtotal' => 1000, 'total' => 1180, 'status' => 'unpaid']);

        $this->artisan('crm:invoice-reminders');
        $this->artisan('crm:invoice-reminders'); // same day: nothing more
        Notification::assertSentToTimes($login, CrmAlert::class, 1);
        Notification::assertSentTo(new AnonymousNotifiable, CrmAlert::class, fn ($n, $ch, $to) => $to->routes['mail'] === 'accounts@b.test');

        foreach (range(1, 5) as $w) {
            Date::setTestNow(now()->addDays(7));
            $this->artisan('crm:invoice-reminders');
        }
        $this->assertSame(4, Invoice::where('invoice_no', 'DFP/2026-27/001')->value('reminders_sent'));
    }

    public function test_bell_alert_is_saved_immediately_even_with_a_real_queue(): void
    {
        config(['queue.default' => 'database']);
        app(\App\Services\Notify::class)->send($this->admin, 'ticket_new', 'New request', 'x', '/tickets/1', true);

        $this->assertSame(1, $this->admin->fresh()->notifications()->count()); // in the bell now
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->count()); // email waits for the worker
    }

    public function test_client_gets_bell_alert_when_invoice_is_issued(): void
    {
        $client = Client::create(['company_name' => 'Green Leaf', 'state' => 'Kerala']);
        $login = User::factory()->client()->create(['client_id' => $client->id]);

        $this->as($this->admin)->postJson('/v1/invoices', ['client_id' => $client->id, 'issue' => true, 'items' => [['description' => 'SEO', 'qty' => 1, 'rate' => 10000]]])->assertCreated();
        $this->assertSame(['invoice_issued'], $this->sentTypes($login));
    }
}

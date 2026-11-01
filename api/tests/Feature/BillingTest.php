<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use App\Notifications\InvoiceIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-02 10:00:00');
        $this->admin = User::factory()->admin()->create();
        Setting::put('company', ['gstin' => '32AAAAA0000A1Z5', 'state' => 'Kerala', 'upi_id' => 'digifox@okexample', 'invoice_prefix' => 'DFP']);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function client(array $extra = []): Client
    {
        return Client::create(['company_name' => 'Green Leaf Organics', 'state' => 'Kerala', 'email' => 'accounts@greenleaf.test', ...$extra]);
    }

    private function draft(Client $c, array $extra = []): array
    {
        return $this->as($this->admin)->postJson('/v1/invoices', [
            'client_id' => $c->id,
            'items' => [
                ['description' => 'Meta ads management – November', 'qty' => 1, 'rate' => 25000],
                ['description' => 'Creative design (posters)', 'qty' => 4, 'rate' => 1250],
            ],
            ...$extra,
        ])->assertCreated()->json();
    }

    private function clientLogin(Client $c): User
    {
        return User::factory()->client()->create(['client_id' => $c->id]);
    }

    public function test_kerala_client_gets_cgst_and_sgst(): void
    {
        $inv = $this->draft($this->client());

        $this->assertSame(['intra', 30000, 2700, 2700, 0, 35400], [$inv['gst_type'], $inv['subtotal'], $inv['cgst'], $inv['sgst'], $inv['igst'], $inv['total']]);
        $this->assertSame('draft', $inv['status']);
        $this->assertNull($inv['invoice_no']);
        $this->assertSame('2026-11-17', $inv['due_date']); // 15-day terms
        $this->assertSame('998361', $inv['items'][0]['sac']);
    }

    public function test_other_state_by_gstin_gets_igst_and_overseas_is_zero_rated(): void
    {
        $blr = $this->draft($this->client(['company_name' => 'BLR Co', 'state' => 'Kerala', 'gstin' => '29ABCDE1234F1Z5']));
        $this->assertSame(['inter', 0, 5400, 35400], [$blr['gst_type'], $blr['cgst'], $blr['igst'], $blr['total']]);

        $uae = $this->draft($this->client(['company_name' => 'Dubai LLC', 'state' => 'Outside India']));
        $this->assertSame(['export', 0, 30000], [$uae['gst_type'], $uae['tax'], $uae['total']]);
    }

    public function test_numbers_run_per_financial_year_and_issued_invoices_are_locked(): void
    {
        $c = $this->client();
        $a = $this->draft($c);
        $b = $this->draft($c);

        $this->postJson("/v1/invoices/{$a['id']}/issue")->assertOk()->assertJsonPath('invoice_no', 'DFP/2026-27/001')->assertJsonPath('status', 'unpaid');
        $this->postJson("/v1/invoices/{$b['id']}/issue")->assertOk()->assertJsonPath('invoice_no', 'DFP/2026-27/002');
        $this->postJson("/v1/invoices/{$a['id']}/issue")->assertUnprocessable();
        $this->putJson("/v1/invoices/{$a['id']}", ['client_id' => $c->id, 'items' => [['description' => 'x', 'qty' => 1, 'rate' => 1]]])->assertUnprocessable();
        $this->deleteJson("/v1/invoices/{$a['id']}")->assertUnprocessable();

        // April starts a new series
        $next = $this->draft($c, ['issue_date' => '2027-04-02']);
        $this->postJson("/v1/invoices/{$next['id']}/issue")->assertJsonPath('invoice_no', 'DFP/2027-28/001');
    }

    public function test_upi_qr_for_the_balance(): void
    {
        $inv = $this->draft($this->client());
        $this->getJson("/v1/invoices/{$inv['id']}")->assertJsonPath('pay.qr', null); // drafts have no QR
        $this->postJson("/v1/invoices/{$inv['id']}/issue");

        $pay = $this->getJson("/v1/invoices/{$inv['id']}")->json('pay');
        $this->assertSame('upi://pay?pa=digifox%40okexample&pn=DIGIFOX%20PRO%20SOLUTIONS%20LLP&am=35400.00&cu=INR&tn=Invoice%20DFP%2F2026-27%2F001&tr=DFP202627001', $pay['upi_link']);
        $this->assertStringStartsWith('data:image/png;base64,', $pay['qr']);
    }

    public function test_client_pays_by_upi_and_admin_confirms(): void
    {
        $c = $this->client();
        $inv = $this->draft($c, ['issue' => true]);
        $login = $this->clientLogin($c);

        $this->as($login)->postJson("/v1/invoices/{$inv['id']}/claims", ['amount' => 35400, 'paid_on' => '2026-11-02', 'mode' => 'upi', 'reference' => '1234'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson("/v1/invoices/{$inv['id']}/claims", ['amount' => 99999, 'paid_on' => '2026-11-02', 'mode' => 'upi', 'reference' => '630512345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $claim = $this->postJson("/v1/invoices/{$inv['id']}/claims", ['amount' => 35400, 'paid_on' => '2026-11-02', 'mode' => 'upi', 'reference' => '630512345678'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json();
        $this->postJson("/v1/invoices/{$inv['id']}/claims", ['amount' => 1, 'paid_on' => '2026-11-02', 'mode' => 'upi', 'reference' => '630512345678'])
            ->assertUnprocessable(); // same UTR twice

        // Clients cannot confirm their own payment
        $this->postJson("/v1/payment-claims/{$claim['id']}/confirm")->assertForbidden();
        $this->assertSame('unpaid', Invoice::find($inv['id'])->status);

        $this->as($this->admin)->getJson('/v1/payment-claims')->assertJsonCount(1, 'data');
        $this->postJson("/v1/payment-claims/{$claim['id']}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertSame(['paid', 35400.0], [Invoice::find($inv['id'])->status, Invoice::find($inv['id'])->amount_paid]);
        $this->postJson("/v1/payment-claims/{$claim['id']}/confirm")->assertUnprocessable();
    }

    public function test_partial_payment_and_rejected_claim(): void
    {
        $c = $this->client();
        $inv = $this->draft($c, ['issue' => true]);

        $this->as($this->admin)->postJson("/v1/invoices/{$inv['id']}/payments", ['amount' => 10000, 'paid_on' => '2026-11-01', 'mode' => 'bank_transfer', 'reference' => 'NEFT123'])
            ->assertCreated()->assertJsonPath('status', 'partial')->assertJsonPath('balance', 25400);
        $this->postJson("/v1/invoices/{$inv['id']}/payments", ['amount' => 30000, 'paid_on' => '2026-11-01', 'mode' => 'upi'])->assertUnprocessable();
        $this->postJson("/v1/invoices/{$inv['id']}/cancel")->assertUnprocessable(); // has payments

        $login = $this->clientLogin($c);
        $claim = $this->as($login)->postJson("/v1/invoices/{$inv['id']}/claims", ['amount' => 25400, 'paid_on' => '2026-11-02', 'mode' => 'upi', 'reference' => '630599999999'])->json();
        $this->as($this->admin)->postJson("/v1/payment-claims/{$claim['id']}/reject")->assertUnprocessable(); // needs a reason
        $this->postJson("/v1/payment-claims/{$claim['id']}/reject", ['note' => 'Not received in bank'])->assertOk();
        $this->assertSame('partial', Invoice::find($inv['id'])->status);
    }

    public function test_clients_only_see_their_own_issued_invoices_and_cannot_create(): void
    {
        $mine = $this->client();
        $other = $this->client(['company_name' => 'Other Co']);
        $issued = $this->draft($mine, ['issue' => true]);
        $draft = $this->draft($mine);
        $theirs = $this->draft($other, ['issue' => true]);
        $login = $this->clientLogin($mine);

        $this->as($login)->getJson('/v1/invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $issued['id']);
        $this->getJson("/v1/invoices/{$draft['id']}")->assertNotFound();
        $this->getJson("/v1/invoices/{$theirs['id']}")->assertNotFound();
        $this->get("/v1/invoices/{$theirs['id']}/pdf")->assertNotFound();
        $this->postJson('/v1/invoices', ['client_id' => $mine->id, 'items' => [['description' => 'x', 'qty' => 1, 'rate' => 1]]])->assertForbidden();
        $this->getJson('/v1/clients')->assertForbidden();

        // Even if the admin wrongly grants invoices.create to clients, staff-only routes stay closed.
        $this->as($this->admin)->putJson('/v1/admin/roles/client/permissions', ['permissions' => ['invoices.view', 'invoices.create', 'invoices.approve']]);
        $this->as($login->fresh())->postJson('/v1/invoices', ['client_id' => $mine->id, 'items' => [['description' => 'x', 'qty' => 1, 'rate' => 1]]])->assertForbidden();
    }

    public function test_staff_see_only_clients_they_manage(): void
    {
        $priya = User::factory()->create();
        $this->as($this->admin)->putJson("/v1/admin/users/{$priya->id}/permissions", ['overrides' => ['clients.view' => true, 'invoices.view' => true]]);
        $hers = $this->client(['account_manager_id' => $priya->id]);
        $this->client(['company_name' => 'Not hers']);
        $this->draft($hers, ['issue' => true]);

        $this->as($priya->fresh())->getJson('/v1/clients')->assertJsonCount(1, 'data')->assertJsonPath('data.0.outstanding', 35400);
        $this->getJson('/v1/invoices')->assertJsonCount(1, 'data')->assertJsonPath('summary.outstanding', 35400);
    }

    public function test_pdf_and_email(): void
    {
        Notification::fake();
        $inv = $this->draft($this->client(), ['issue' => true]);

        $pdf = $this->as($this->admin)->get("/v1/invoices/{$inv['id']}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('Invoice-DFP-2026-27-001.pdf', $pdf->headers->get('content-disposition'));

        $this->postJson("/v1/invoices/{$inv['id']}/email")->assertOk()->assertJsonPath('sent_to', 'accounts@greenleaf.test');
        Notification::assertSentTo(new AnonymousNotifiable, InvoiceIssued::class);
    }

    public function test_client_invite_and_onboarding_link_to_the_client(): void
    {
        Notification::fake();
        $c = $this->client();
        $this->as($this->admin)->postJson("/v1/clients/{$c->id}/invite", ['name' => 'Anu', 'email' => 'anu@greenleaf.test'])->assertCreated()
            ->assertJsonPath('status', 'pending_onboarding');
        $user = User::where('email', 'anu@greenleaf.test')->first();
        Notification::assertSentTo($user, EmployeeInvite::class);

        $this->as($user)->postJson('/v1/onboarding', ['full_name' => 'Asha Menon', 'business_name' => 'Green Leaf Organics Pvt Ltd', 'email' => 'asha@example.com', 'phone' => '+91 98470 12345', 'google_analytics' => 'yes', 'search_console' => 'no', 'google_ads' => 'no', 'goals' => ['SEO'], 'target_audience' => 'Health-conscious families in Kochi', 'state' => 'Kerala'])->assertOk();
        $this->assertSame(1, Client::count());
        $this->assertSame('Green Leaf Organics Pvt Ltd', $c->fresh()->company_name);

        // Self-registered clients get a new client record on onboarding
        $res = $this->postJson('/v1/auth/register', ['name' => 'Ravi', 'company_name' => 'Ravi Motors', 'email' => 'ravi@x.test', 'phone' => '+91 98470 55555', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('token'))->postJson('/v1/onboarding', ['full_name' => 'Asha Menon', 'business_name' => 'Ravi Motors', 'email' => 'asha@example.com', 'phone' => '+91 98470 12345', 'google_analytics' => 'yes', 'search_console' => 'no', 'google_ads' => 'no', 'goals' => ['SEO'], 'target_audience' => 'Health-conscious families in Kochi', 'state' => 'Kerala'])->assertOk();
        $this->assertSame(Client::where('company_name', 'Ravi Motors')->value('id'), User::where('email', 'ravi@x.test')->value('client_id'));
    }

    public function test_company_settings_validate_indian_formats(): void
    {
        $base = ['legal_name' => 'DIGIFOX PRO SOLUTIONS LLP', 'state' => 'Kerala', 'invoice_prefix' => 'DFP', 'default_gst_rate' => 18, 'payment_terms_days' => 15];

        $this->as($this->admin)->putJson('/v1/settings/company', [...$base, 'gstin' => '32AAAAA0000A1Z5', 'upi_id' => 'digifox@okexample', 'bank_ifsc' => 'ABCD0123456', 'bank_account_number' => '000123456789'])
            ->assertOk()->assertJsonPath('company.upi_id', 'digifox@okexample');
        $this->putJson('/v1/settings/company', [...$base, 'bank_ifsc' => 'ABCD 0123 456', 'upi_id' => 'not-a-upi'])
            ->assertUnprocessable()->assertJsonValidationErrors(['bank_ifsc', 'upi_id']);

        $this->as(User::factory()->create())->getJson('/v1/settings/company')->assertForbidden();
    }
}

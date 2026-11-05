<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RazorpayTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-11-05 10:00:00');
        config(['services.razorpay' => ['key_id' => 'rzp_test_abc', 'key_secret' => 'key-secret', 'webhook_secret' => 'hook-secret']]);
        $this->admin = User::factory()->admin()->create();
        Setting::put('company', ['gstin' => '32AAAAA0000A1Z5', 'state' => 'Kerala', 'invoice_prefix' => 'DFP']);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    /** An issued invoice of Rs. 11,800 (10,000 + 18% GST). */
    private function invoice(): array
    {
        $c = Client::create(['company_name' => 'Green Leaf', 'state' => 'Kerala', 'phone' => '98470 12345', 'email' => 'a@greenleaf.test']);
        $inv = $this->as($this->admin)->postJson('/v1/invoices', ['client_id' => $c->id, 'issue' => true,
            'items' => [['description' => 'SEO', 'qty' => 1, 'rate' => 10000]]])->assertCreated()->json();

        return [$c, $inv];
    }

    private function fakeRazorpay(string $id = 'plink_123'): void
    {
        Http::fake(['api.razorpay.com/v1/payment_links' => Http::response(['id' => $id, 'short_url' => 'https://rzp.io/i/'.$id, 'status' => 'created'])]);
    }

    public function test_client_gets_a_link_for_the_balance_and_it_is_reused(): void
    {
        [$c, $inv] = $this->invoice();
        $this->fakeRazorpay();
        $client = User::factory()->client()->create(['client_id' => $c->id]);

        $this->as($client)->getJson("/v1/invoices/{$inv['id']}")->assertJsonPath('pay.online', true);
        $this->postJson("/v1/invoices/{$inv['id']}/pay-online")->assertOk()->assertJsonPath('url', 'https://rzp.io/i/plink_123')->assertJsonPath('amount', 11800);
        $this->postJson("/v1/invoices/{$inv['id']}/pay-online")->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['amount'] === 1180000 && $r['customer']['contact'] === '+919847012345'
            && $r['callback_url'] === config('app.frontend_url').'/pay/done' && $r->hasHeader('Authorization'));

        // Someone else's invoice: no.
        $other = User::factory()->client()->create(['client_id' => Client::create(['company_name' => 'X'])->id]);
        $this->as($other)->postJson("/v1/invoices/{$inv['id']}/pay-online")->assertNotFound();
    }

    public function test_webhook_records_the_payment_once(): void
    {
        [, $inv] = $this->invoice();
        $this->fakeRazorpay();
        $this->as($this->admin)->postJson("/v1/invoices/{$inv['id']}/pay-online")->assertOk();

        $body = json_encode(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_123', 'status' => 'paid']],
            'payment' => ['entity' => ['id' => 'pay_ABC', 'amount' => 1180000, 'fee' => 27848, 'method' => 'upi']],
        ]]);
        $sig = hash_hmac('sha256', $body, 'hook-secret');
        $this->app['auth']->forgetGuards();

        $this->call('POST', '/v1/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => 'bad'], $body)->assertStatus(400);
        $this->call('POST', '/v1/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $sig], $body)->assertOk();
        $this->call('POST', '/v1/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $sig], $body)->assertOk();

        $invoice = Invoice::find($inv['id']);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(1, $invoice->payments()->count());
        $p = $invoice->payments()->first();
        $this->assertSame('online', $p->mode);
        $this->assertSame('pay_ABC', $p->reference);
        $this->assertEquals(278.48, $p->gateway_fee);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id]);
    }

    public function test_return_from_razorpay_is_verified_and_recorded(): void
    {
        [, $inv] = $this->invoice();
        $this->fakeRazorpay();
        $this->as($this->admin)->postJson("/v1/invoices/{$inv['id']}/pay-online");
        $this->app['auth']->forgetGuards();

        $p = ['razorpay_payment_id' => 'pay_XYZ', 'razorpay_payment_link_id' => 'plink_123',
            'razorpay_payment_link_reference_id' => 'INV1', 'razorpay_payment_link_status' => 'paid'];
        $this->postJson('/v1/payments/razorpay/callback', $p + ['razorpay_signature' => 'forged'])->assertStatus(422);
        $this->assertSame('unpaid', Invoice::find($inv['id'])->status);

        $sig = hash_hmac('sha256', 'plink_123|INV1|paid|pay_XYZ', 'key-secret');
        $this->postJson('/v1/payments/razorpay/callback', $p + ['razorpay_signature' => $sig])->assertOk()
            ->assertJsonPath('paid', true)->assertJsonPath('balance', 0);
        $this->assertSame('paid', Invoice::find($inv['id'])->status);
    }

    public function test_not_set_up_or_razorpay_errors_give_a_clear_message(): void
    {
        [, $inv] = $this->invoice();
        Http::fake(['api.razorpay.com/*' => Http::response(['error' => ['description' => 'Authentication failed']], 401)]);
        $this->as($this->admin)->postJson("/v1/invoices/{$inv['id']}/pay-online")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'API keys'));

        config(['services.razorpay.key_id' => null]);
        $this->getJson("/v1/invoices/{$inv['id']}")->assertJsonPath('pay.online', false);
        $this->postJson("/v1/invoices/{$inv['id']}/pay-online")->assertStatus(422);
    }
}

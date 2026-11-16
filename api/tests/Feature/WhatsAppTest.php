<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Amit']);
        config(['services.meta.app_secret' => 'app-secret', 'services.meta.verify_token' => 'verify-me']);
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*/111222333444?*' => Http::response(['display_phone_number' => '+91 90000 00000', 'verified_name' => 'DigiFox Pro']),
            'graph.facebook.com/*/555666777888/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/*/555666777888/message_templates*' => Http::response(['data' => [
                ['name' => 'lead_welcome', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING', 'components' => [
                    ['type' => 'BODY', 'text' => 'Hi {{1}}, thanks for your interest in {{2}}. When can we call you?'],
                    ['type' => 'FOOTER', 'text' => 'DigiFox Pro Solutions'],
                ]],
                ['name' => 'with_image', 'language' => 'en', 'status' => 'APPROVED', 'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Hi']]],
                ['name' => 'pending_one', 'language' => 'en', 'status' => 'PENDING', 'components' => [['type' => 'BODY', 'text' => 'Hi']]],
            ]]),
            'graph.facebook.com/*/111222333444/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.OUT1']]])
                ->push(['messages' => [['id' => 'wamid.OUT2']]])
                ->push(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
        ]);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function connect(): void
    {
        $this->as($this->admin)->postJson('/v1/integrations/whatsapp', ['phone_number_id' => '111222333444', 'waba_id' => '555666777888', 'access_token' => str_repeat('t', 40)])
            ->assertOk()->assertJsonPath('connected', true)->assertJsonPath('display_phone', '+91 90000 00000');
    }

    private function hook(array $value): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '555666777888', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);

        return $this->call('POST', '/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }

    public function test_phone_numbers_are_normalised(): void
    {
        $this->assertSame('919876543210', WhatsApp::phone('+91 98765 43210'));
        $this->assertSame('919876543210', WhatsApp::phone('098765-43210'));
        $this->assertSame('919876543210', WhatsApp::phone('9876543210'));
        $this->assertSame('971501234567', WhatsApp::phone('+971 50 123 4567'));
        $this->assertNull(WhatsApp::phone('12345'));
    }

    public function test_connect_templates_send_and_the_24_hour_rule(): void
    {
        $this->connect();
        $this->assertStringNotContainsString(str_repeat('t', 40), json_encode(\App\Models\Setting::all())); // token stored encrypted

        $priya = User::factory()->create(['name' => 'Priya', 'role' => 'employee', 'status' => 'active']);
        $lead = Lead::create(['full_name' => 'Rahul Nair', 'phone' => '+91 98765 43210', 'status' => 'new', 'assigned_to' => $priya->id]);

        $this->as($priya)->getJson('/v1/whatsapp/templates')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'lead_welcome')->assertJsonPath('data.0.body_vars', 2);

        // No message from them yet: free text is refused, a template works.
        $this->postJson('/v1/whatsapp/send', ['lead_id' => $lead->id, 'text' => 'Hello'])->assertStatus(422)->assertJsonValidationErrors('text');
        $this->postJson('/v1/whatsapp/send', ['lead_id' => $lead->id, 'template' => 'lead_welcome', 'body_values' => ['Rahul']])->assertStatus(422);
        $this->postJson('/v1/whatsapp/send', ['lead_id' => $lead->id, 'template' => 'lead_welcome', 'body_values' => ['Rahul', 'SEO']])
            ->assertCreated()->assertJsonPath('body', "Hi Rahul, thanks for your interest in SEO. When can we call you?\n\nDigiFox Pro Solutions");
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === '919876543210' && $r['template']['components'][0]['parameters'][1]['text'] === 'SEO');
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'whatsapp']);

        // They reply: matched to the lead, Priya is told, and free text is now allowed.
        $this->hook(['contacts' => [['wa_id' => '919876543210', 'profile' => ['name' => 'Rahul N']]],
            'messages' => [['from' => '919876543210', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'Call me at 5']]]])->assertOk();
        $this->assertDatabaseHas('whatsapp_messages', ['wa_id' => 'wamid.IN1', 'lead_id' => $lead->id, 'direction' => 'in']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $priya->id, 'data->type' => 'whatsapp_in']);
        $this->as($priya)->getJson('/v1/whatsapp/inbox')->assertJsonPath('data.0.name', 'Rahul Nair')->assertJsonPath('data.0.unread', 1);
        $this->getJson('/v1/whatsapp/thread?lead_id='.$lead->id)->assertJsonPath('window_open', true)->assertJsonCount(2, 'data');
        $this->getJson('/v1/whatsapp/inbox')->assertJsonPath('data.0.unread', 0); // opened = seen
        $this->postJson('/v1/whatsapp/send', ['lead_id' => $lead->id, 'text' => 'Sure, 5 pm it is.'])->assertCreated();

        // Delivery ticks never step back; failures keep Meta's reason.
        $this->hook(['statuses' => [['id' => 'wamid.OUT1', 'status' => 'read']]]);
        $this->hook(['statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered']]]);
        $this->assertSame('read', WhatsAppMessage::where('wa_id', 'wamid.OUT1')->value('status'));
        $this->hook(['statuses' => [['id' => 'wamid.OUT2', 'status' => 'failed', 'errors' => [['title' => 'Message undeliverable']]]]]);
        $this->assertDatabaseHas('whatsapp_messages', ['wa_id' => 'wamid.OUT2', 'status' => 'failed', 'error' => 'Message undeliverable']);

        // Meta's error codes come back in plain words.
        $this->postJson('/v1/whatsapp/send', ['lead_id' => $lead->id, 'text' => 'One more'])
            ->assertStatus(422)->assertJsonPath('errors.text.0', 'More than 24 hours since their last message: send an approved template instead.');
    }

    public function test_privacy_and_signatures(): void
    {
        $this->connect();
        $priya = User::factory()->create(['name' => 'Priya', 'role' => 'employee', 'status' => 'active']);
        $arjun = User::factory()->create(['name' => 'Arjun', 'role' => 'employee', 'status' => 'active']);
        $lead = Lead::create(['full_name' => 'Rahul', 'phone' => '9876543210', 'status' => 'new', 'assigned_to' => $arjun->id]);
        Client::create(['company_name' => 'Spice Route', 'phone' => '9123456780', 'status' => 'active']);

        // Bad signature: ignored.
        $this->call('POST', '/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], '{}')->assertForbidden();
        $this->get('/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=42')->assertOk()->assertSee('42');

        // A message from an unknown number and one from the client.
        $this->hook(['messages' => [['from' => '919000011111', 'id' => 'wamid.U1', 'type' => 'text', 'text' => ['body' => 'Price?']],
            ['from' => '919123456780', 'id' => 'wamid.C1', 'type' => 'image', 'image' => ['caption' => 'New logo']]]]);
        $this->assertDatabaseHas('whatsapp_messages', ['wa_id' => 'wamid.C1', 'body' => 'New logo [image]']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->admin->id, 'data->type' => 'whatsapp_in']);

        // Priya (own leads only) sees neither Arjun's lead nor unknown numbers.
        $this->as($priya)->getJson('/v1/whatsapp/thread?lead_id='.$lead->id)->assertNotFound();
        $this->getJson('/v1/whatsapp/thread?phone=919000011111')->assertForbidden();
        $this->getJson('/v1/whatsapp/inbox')->assertJsonCount(0, 'data');
        $this->as($this->admin)->getJson('/v1/whatsapp/inbox')->assertJsonCount(2, 'data');

        // Clients never reach WhatsApp.
        $c = User::factory()->create(['role' => 'client', 'status' => 'active']);
        $this->as($c)->getJson('/v1/whatsapp/inbox')->assertForbidden();
    }
}

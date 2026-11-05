<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailAndClientDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
        Setting::put('company', ['gstin' => '32AAAAA0000A1Z5', 'state' => 'Kerala', 'invoice_prefix' => 'DFP']);
    }

    public function test_changing_an_employees_office_email_changes_their_login(): void
    {
        $u = User::factory()->create(['email' => 'priya.old@example.test', 'password' => 'Secret123', 'google_id' => 'g-1', 'role' => 'employee', 'status' => 'active']);
        $e = Employee::create(['emp_code' => 'DFP002', 'name' => 'Priya', 'office_email' => 'priya.old@example.test', 'user_id' => $u->id]);
        User::factory()->create(['email' => 'taken@example.test']);

        $this->putJson("/v1/employees/{$e->id}", ['name' => 'Priya', 'office_email' => 'taken@example.test'])->assertStatus(422)->assertJsonValidationErrors('office_email');
        $this->putJson("/v1/employees/{$e->id}", ['name' => 'Priya', 'office_email' => 'Priya@DigiFox.test'])->assertOk();

        $u->refresh();
        $this->assertSame('priya@digifox.test', $u->email);
        $this->assertNull($u->google_id);
        $this->app['auth']->forgetGuards();
        $this->postJson('/v1/auth/login', ['email' => 'priya@digifox.test', 'password' => 'Secret123'])->assertOk();
    }

    public function test_admin_changes_any_login_email_in_users_and_access(): void
    {
        $c = User::factory()->client()->create(['email' => 'old@client.test']);
        $this->patchJson("/v1/admin/users/{$c->id}", ['email' => 'new@client.test'])->assertOk()->assertJsonPath('email', 'new@client.test');
        $this->patchJson("/v1/admin/users/{$c->id}", ['email' => $this->admin->email])->assertStatus(422);
    }

    public function test_delete_a_client_without_invoices_but_not_one_with_gst_records(): void
    {
        $test = Client::create(['company_name' => 'Test Client']);
        $login = User::factory()->client()->create(['client_id' => $test->id]);
        Ticket::create(['client_id' => $test->id, 'subject' => 'Hello', 'status' => 'open', 'priority' => 'medium']);
        $this->deleteJson("/v1/clients/{$test->id}")->assertOk();
        $this->assertModelMissing($test);
        $this->assertModelMissing($login);
        $this->assertSame(0, Ticket::count());

        $real = Client::create(['company_name' => 'Real Client', 'state' => 'Kerala']);
        $this->postJson('/v1/invoices', ['client_id' => $real->id, 'issue' => true, 'items' => [['description' => 'SEO', 'qty' => 1, 'rate' => 1000]]])->assertCreated();
        $this->deleteJson("/v1/clients/{$real->id}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'GST'));
        $this->assertModelExists($real);

        $emp = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        Sanctum::actingAs($emp);
        $this->deleteJson("/v1/clients/{$real->id}")->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_admin_has_every_permission(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->hasPermission('expenses.delete'));
        $this->assertCount(count(config('crm.modules')) * 6, $admin->permissionKeys());
    }

    public function test_employee_gets_role_defaults(): void
    {
        $employee = User::factory()->create();

        $this->assertTrue($employee->hasPermission('leave.create'));
        $this->assertFalse($employee->hasPermission('leads.view'));
    }

    public function test_non_admin_cannot_open_admin_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/v1/admin/users')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_can_change_role_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->putJson('/v1/admin/roles/employee/permissions', [
            'permissions' => ['dashboard.view', 'leads.view', 'leads.edit'],
        ])->assertOk()->assertJsonPath('roles.employee', ['dashboard.view', 'leads.edit', 'leads.view']);

        $this->assertTrue($employee->fresh()->hasPermission('leads.view'));
        $this->assertFalse($employee->fresh()->hasPermission('leave.create'));
    }

    public function test_clients_can_never_get_internal_modules(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/v1/admin/roles/client/permissions', [
            'permissions' => ['tickets.view', 'employees.view', 'leads.view'],
        ])->assertOk()->assertJsonPath('roles.client', ['tickets.view']);

        // Even a direct per-user grant is ignored for a client.
        $client = User::factory()->client()->create();
        $this->putJson("/v1/admin/users/{$client->id}/permissions", ['overrides' => ['leads.view' => true]])->assertOk();
        $this->assertFalse($client->fresh()->hasPermission('leads.view'));
    }

    public function test_per_user_overrides_grant_and_deny(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->create();
        Sanctum::actingAs($admin);

        $res = $this->putJson("/v1/admin/users/{$employee->id}/permissions", [
            'overrides' => ['leads.view' => true, 'leave.create' => false],
        ])->assertOk();
        $this->assertSame(['leads.view' => true, 'leave.create' => false], $res->json('overrides'));

        $fresh = $employee->fresh();
        $this->assertTrue($fresh->hasPermission('leads.view'));
        $this->assertFalse($fresh->hasPermission('leave.create'));
    }

    public function test_employee_granted_settings_can_view_users(): void
    {
        $employee = User::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/v1/admin/users/{$employee->id}/permissions", ['overrides' => ['settings.view' => true]]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($employee->fresh());
        $this->getJson('/v1/admin/users')->assertOk();
        $this->patchJson("/v1/admin/users/{$employee->id}", ['status' => 'inactive'])->assertForbidden();
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/v1/admin/users/{$admin->id}", ['role' => 'employee'])->assertUnprocessable();
    }

    public function test_deactivating_a_user_signs_them_out(): void
    {
        $employee = User::factory()->create();
        $employee->createToken('phone');
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/v1/admin/users/{$employee->id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_create_admin_command(): void
    {
        $this->artisan('crm:create-admin', ['--name' => 'Amit', '--email' => 'Owner@Digifox.test'])
            ->expectsQuestion('Password (min 8 characters, letters and numbers)', 'strongpass1')
            ->assertSuccessful();

        $this->assertTrue(User::where('email', 'owner@digifox.test')->first()->isAdmin());
    }
}

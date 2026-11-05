<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_change_own_name_and_phone(): void
    {
        $u = User::factory()->create(['name' => 'Admin', 'role' => 'admin', 'status' => 'active']);
        Employee::create(['emp_code' => 'DFP001', 'name' => 'Admin', 'user_id' => $u->id]);
        $token = $u->createToken('web')->plainTextToken;

        $this->withToken($token)->putJson('/v1/auth/profile', ['name' => 'Monisa R', 'phone' => '+91 98470 12345'])->assertOk()
            ->assertJsonPath('name', 'Monisa R')->assertJsonPath('phone', '+91 98470 12345');
        $this->assertSame('Monisa R', Employee::first()->name);
        $this->withToken($token)->putJson('/v1/auth/profile', ['name' => ''])->assertStatus(422);
    }

    public function test_change_password_signs_out_other_devices(): void
    {
        $u = User::factory()->create(['password' => 'OldPass123', 'status' => 'active']);
        $here = $u->createToken('laptop')->plainTextToken;
        $u->createToken('phone');

        $this->withToken($here)->postJson('/v1/auth/password', ['current_password' => 'wrong', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->withToken($here)->postJson('/v1/auth/password', ['current_password' => 'OldPass123', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->withToken($here)->postJson('/v1/auth/password', ['current_password' => 'OldPass123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'])->assertOk();

        $this->assertTrue(Hash::check('NewPass456', $u->fresh()->password));
        $this->assertSame(1, $u->tokens()->count()); // only this device
        $this->app['auth']->forgetGuards();
        $this->withToken($here)->getJson('/v1/auth/me')->assertOk();
    }
}

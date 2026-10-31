<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function registerPayload(array $overrides = []): array
    {
        return [
            'name' => 'Asha Menon',
            'company_name' => 'Green Leaf Organics',
            'email' => 'Asha@Example.com',
            'phone' => '+91 98470 12345',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            ...$overrides,
        ];
    }

    public function test_client_can_register_and_must_onboard(): void
    {
        $res = $this->postJson('/v1/auth/register', $this->registerPayload())
            ->assertCreated()
            ->assertJsonPath('user.role', 'client')
            ->assertJsonPath('user.email', 'asha@example.com')
            ->assertJsonPath('user.needs_onboarding', true);

        $token = $res->json('token');

        $this->withToken($token)->getJson('/v1/admin/users')
            ->assertForbidden()->assertJsonPath('code', 'ONBOARDING_REQUIRED');
        $this->withToken($token)->getJson('/v1/auth/me')->assertOk();
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/v1/auth/register', $this->registerPayload([
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'phone' => 'abc',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'phone']);
    }

    public function test_onboarding_activates_client(): void
    {
        $token = $this->postJson('/v1/auth/register', $this->registerPayload())->json('token');

        $this->withToken($token)->postJson('/v1/onboarding', [
            'business_name' => 'Green Leaf Organics',
            'industry' => 'Retail',
            'city' => 'Kochi',
            'state' => 'Kerala',
            'gstin' => '32ABCDE1234F1Z5',
            'services' => ['Meta Ads', 'SEO'],
        ])->assertOk()->assertJsonPath('user.status', 'active');

        $this->withToken($token)->getJson('/v1/auth/me')
            ->assertJsonPath('user.needs_onboarding', false)
            ->assertJsonPath('modules', ['campaigns', 'dashboard', 'invoices', 'tickets']);
    }

    public function test_login_returns_token_and_profile(): void
    {
        $user = User::factory()->create(['email' => 'priya@digifox.test']);

        $this->postJson('/v1/auth/login', ['email' => 'PRIYA@digifox.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'role'], 'permissions', 'modules'])
            ->assertJsonPath('user.id', $user->id);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected_and_locks_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'priya@digifox.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['email' => 'priya@digifox.test', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        // Even the right password is refused while locked.
        $this->postJson('/v1/auth/login', ['email' => 'priya@digifox.test', 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->status('inactive')->create(['email' => 'old@digifox.test']);

        $this->postJson('/v1/auth/login', ['email' => 'old@digifox.test', 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public function test_logout_revokes_token(): void
    {
        User::factory()->create(['email' => 'priya@digifox.test']);
        $token = $this->postJson('/v1/auth/login', ['email' => 'priya@digifox.test', 'password' => 'password'])->json('token');

        $this->withToken($token)->postJson('/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/v1/auth/me')->assertUnauthorized();
    }

    public function test_forgot_password_sends_link_to_frontend_without_revealing_accounts(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://crm.example.test']);
        $user = User::factory()->create(['email' => 'priya@digifox.test']);

        $this->postJson('/v1/auth/forgot-password', ['email' => 'priya@digifox.test'])->assertOk();
        $this->postJson('/v1/auth/forgot-password', ['email' => 'nobody@digifox.test'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://crm.example.test/reset-password?token=');
        });
        Notification::assertCount(1);
    }

    public function test_password_can_be_reset_and_old_sessions_are_revoked(): void
    {
        $user = User::factory()->create(['email' => 'priya@digifox.test']);
        $user->createToken('old-device');
        $token = Password::createToken($user);

        $this->postJson('/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'priya@digifox.test',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/v1/auth/login', ['email' => 'priya@digifox.test', 'password' => 'newpass123'])->assertOk();
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'priya@digifox.test']);

        $this->postJson('/v1/auth/reset-password', [
            'token' => 'bogus',
            'email' => 'priya@digifox.test',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertUnprocessable();
    }
}

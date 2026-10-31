<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CLIENT_ID = 'test-client.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => self::CLIENT_ID]);
    }

    private function fakeGoogle(array $claims = [], int $status = 200): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => self::CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'exp' => (string) (time() + 3600),
            'sub' => '1234567890',
            'email' => 'Asha@Gmail.com',
            'email_verified' => 'true',
            'name' => 'Asha Menon',
            ...$claims,
        ], $status)]);
    }

    public function test_config_exposes_client_id_only_when_enabled(): void
    {
        $this->getJson('/v1/auth/config')->assertExactJson(['google_client_id' => self::CLIENT_ID]);

        config(['services.google.client_id' => null]);
        $this->getJson('/v1/auth/config')->assertExactJson(['google_client_id' => null]);
        $this->postJson('/v1/auth/google', ['credential' => 'x'])->assertNotFound();
    }

    public function test_new_google_user_becomes_a_client_who_must_onboard(): void
    {
        $this->fakeGoogle();

        $this->postJson('/v1/auth/google', ['credential' => 'token'])
            ->assertCreated()
            ->assertJsonPath('user.email', 'asha@gmail.com')
            ->assertJsonPath('user.role', 'client')
            ->assertJsonPath('user.needs_onboarding', true)
            ->assertJsonStructure(['token']);

        $this->assertSame('1234567890', User::first()->google_id);
    }

    public function test_existing_employee_signs_in_with_google_by_email(): void
    {
        $priya = User::factory()->create(['email' => 'asha@gmail.com']);
        $this->fakeGoogle();

        $this->postJson('/v1/auth/google', ['credential' => 'token'])
            ->assertOk()
            ->assertJsonPath('user.id', $priya->id)
            ->assertJsonPath('user.role', 'employee');

        $this->assertSame(1, User::count());
        $this->assertSame('1234567890', $priya->fresh()->google_id);
    }

    public function test_deactivated_user_cannot_use_google(): void
    {
        User::factory()->status('inactive')->create(['email' => 'asha@gmail.com']);
        $this->fakeGoogle();

        $this->postJson('/v1/auth/google', ['credential' => 'token'])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
    }

    public static function badTokens(): array
    {
        return [
            'issued for another app' => [['aud' => 'someone-elses-app'], 200],
            'email not verified' => [['email_verified' => 'false'], 200],
            'expired' => [['exp' => (string) (time() - 10)], 200],
            'wrong issuer' => [['iss' => 'evil.example.com'], 200],
            'rejected by Google' => [['error' => 'invalid_token'], 400],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function test_invalid_google_tokens_are_rejected(array $claims, int $status): void
    {
        $this->fakeGoogle($claims, $status);

        $this->postJson('/v1/auth/google', ['credential' => 'token'])->assertUnprocessable();
        $this->assertSame(0, User::count());
    }
}

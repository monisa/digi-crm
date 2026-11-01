<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function newClientLogin(): User
    {
        return User::factory()->create(['role' => 'client', 'status' => 'pending_onboarding', 'name' => 'Asha', 'company_name' => 'Green Leaf']);
    }

    private function form(array $extra = []): array
    {
        return [
            'full_name' => 'Asha Menon', 'business_name' => 'Green Leaf Organics', 'email' => 'asha@greenleaf.test', 'phone' => '+91 98470 12345',
            'website' => 'https://greenleaf.test', 'industry' => 'Retail',
            'social_platforms' => ['Facebook', 'Instagram'],
            'facebook_url' => 'https://facebook.com/greenleaf', 'facebook_admin_access' => 'yes',
            'instagram_handle' => '@greenleaf', 'instagram_email' => 'insta@greenleaf.test', 'instagram_password' => 'S3cret-Insta!',
            'website_cms' => 'WordPress', 'website_admin_url' => 'https://greenleaf.test/wp-admin', 'website_username' => 'admin', 'website_password' => 'Wp-Pass-123',
            'brand_notes' => 'Green #2E7D32, font Poppins',
            'google_analytics' => 'yes', 'search_console' => 'no', 'google_ads' => 'yes', 'google_business_url' => 'https://g.page/greenleaf',
            'goals' => ['Lead Generation', 'SEO'], 'goals_other' => 'WhatsApp orders',
            'target_audience' => 'Health-conscious families in Kochi, 25–45',
            'challenges' => 'Low Instagram reach', 'competitors' => 'organicbazaar.test',
            'city' => 'Kochi', 'state' => 'Kerala', 'gstin' => '32ABCDE1234F1Z5',
            ...$extra,
        ];
    }

    public function test_form_options_and_prefill(): void
    {
        $this->as($this->newClientLogin())->getJson('/v1/onboarding')->assertOk()
            ->assertJsonPath('prefill.full_name', 'Asha')
            ->assertJsonPath('options.platforms', ['Facebook', 'Instagram', 'LinkedIn', 'Twitter', 'YouTube'])
            ->assertJsonCount(8, 'options.goals');
    }

    public function test_required_questions(): void
    {
        $this->as($this->newClientLogin())->postJson('/v1/onboarding', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'business_name', 'email', 'phone', 'google_analytics', 'search_console', 'google_ads', 'goals', 'target_audience']);
    }

    public function test_submission_stores_answers_encrypts_logins_and_keeps_files_private(): void
    {
        Storage::fake('local');
        $user = $this->newClientLogin();

        $this->as($user)->post('/v1/onboarding', $this->form([
            'logo' => UploadedFile::fake()->image('logo.png', 400, 400),
            'brand_files' => [UploadedFile::fake()->create('style-guide.pdf', 300, 'application/pdf')],
        ]), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('user.status', 'active');

        $client = Client::first();
        $this->assertSame(['Green Leaf Organics', 'Asha Menon', 'Kerala', '32ABCDE1234F1Z5', 'Retail'],
            [$client->company_name, $client->contact_name, $client->state, $client->gstin, $client->industry]);
        $this->assertSame($client->id, $user->fresh()->client_id);

        // Answers keep everything except the logins.
        $answers = json_encode($client->onboarding_data);
        $this->assertStringContainsString('Health-conscious families', $answers);
        $this->assertStringNotContainsString('S3cret-Insta!', $answers);
        $this->assertStringNotContainsString('Wp-Pass-123', $answers);
        $this->assertSame(['instagram', 'website'], $client->onboarding_data['shared_logins']);

        // Logins are encrypted at rest.
        $raw = DB::table('clients')->value('credentials');
        $this->assertStringNotContainsString('S3cret-Insta!', $raw);
        $this->assertSame('S3cret-Insta!', $client->credentials['instagram']['password']);

        // Files are on the private disk.
        $this->assertSame(['logo', 'brand_asset'], $client->files->sortBy('id')->pluck('kind')->values()->all());
        Storage::disk('local')->assertExists($client->files->first()->path);

        $this->postJson('/v1/onboarding', $this->form())->assertStatus(409);
    }

    public function test_only_permitted_staff_can_reveal_logins_and_it_is_logged(): void
    {
        $this->as($this->newClientLogin())->postJson('/v1/onboarding', $this->form())->assertOk();
        $client = Client::first();
        $admin = User::factory()->admin()->create(['name' => 'Amit']);
        $manager = User::factory()->create();
        $client->update(['account_manager_id' => $manager->id]);
        $this->as($admin)->putJson("/v1/admin/users/{$manager->id}/permissions", ['overrides' => ['clients.view' => true]]);

        // Client profile never includes the logins
        $this->getJson("/v1/clients/{$client->id}")->assertOk()->assertJsonPath('has_credentials', true)->assertJsonMissingPath('credentials');
        $this->assertStringNotContainsString('S3cret', $this->getJson('/v1/clients')->getContent());

        // Manager without "approve" cannot reveal
        $this->as($manager->fresh())->postJson("/v1/clients/{$client->id}/credentials")->assertForbidden();

        // Client logins cannot either
        $this->as(User::where('role', 'client')->first())->postJson("/v1/clients/{$client->id}/credentials")->assertForbidden();

        $this->as($admin)->postJson("/v1/clients/{$client->id}/credentials")->assertOk()
            ->assertJsonPath('credentials.instagram.password', 'S3cret-Insta!')
            ->assertJsonPath('views.0.name', 'Amit');
        $this->assertSame(1, DB::table('credential_views')->count());
    }

    public function test_brand_files_download_only_for_people_who_see_the_client(): void
    {
        Storage::fake('local');
        $this->as($this->newClientLogin())->post('/v1/onboarding', $this->form(['logo' => UploadedFile::fake()->image('logo.png')]), ['Accept' => 'application/json'])->assertOk();
        $file = Client::first()->files->first();

        $res = $this->as(User::factory()->admin()->create())->get("/v1/client-files/{$file->id}")->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));

        $this->as(User::factory()->create())->get("/v1/client-files/{$file->id}", ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_dangerous_uploads_are_refused(): void
    {
        $this->as($this->newClientLogin())->post('/v1/onboarding', $this->form(['logo' => UploadedFile::fake()->create('shell.php', 1, 'application/x-php')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('logo');
    }
}

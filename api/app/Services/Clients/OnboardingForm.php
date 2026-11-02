<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\User;
use App\Services\Billing\Gst;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * "Digital Marketing Client Onboarding Form": one set of questions used by
 * the client after first login, by the public form link, and by staff filling
 * it in for a client. Shared passwords are kept encrypted and apart from the
 * other answers.
 */
class OnboardingForm
{
    /** Passwords / logins that go into the encrypted credentials store, not the answers. */
    public const SECRET = ['instagram_email', 'instagram_password', 'twitter_email', 'twitter_password', 'website_admin_url', 'website_username', 'website_password'];

    private const FILE_TYPES = 'png,jpg,jpeg,webp,svg,pdf,ai,eps,psd,zip';

    /** Dropdown options for the form. */
    public function options(): array
    {
        return config('crm.onboarding') + ['states' => [...array_values(Gst::STATES), 'Outside India']];
    }

    /**
     * Validate the answers. Staff filling it in on a call may not know
     * everything, so for them only the business name is required.
     */
    public function validate(Request $request, bool $staff = false): array
    {
        $yesNo = ['nullable', Rule::in(['yes', 'no'])];
        $text = ['nullable', 'string', 'max:255'];
        $long = ['nullable', 'string', 'max:3000'];
        $req = $staff ? 'nullable' : 'required';
        $o = config('crm.onboarding');

        return $request->validate([
            'full_name' => [$req, 'string', 'max:120'],
            'business_name' => ['required', 'string', 'max:160'],
            'email' => [$req, 'email', 'max:190'],
            'phone' => [$req, 'string', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
            'website' => $text,
            'industry' => $text,

            'social_platforms' => ['nullable', 'array'],
            'social_platforms.*' => [Rule::in($o['platforms'])],
            'facebook_url' => $text, 'facebook_admin_access' => $yesNo,
            'instagram_handle' => $text, 'instagram_email' => $text, 'instagram_password' => $text,
            'linkedin_url' => $text, 'linkedin_admin_access' => $yesNo,
            'twitter_handle' => $text, 'twitter_email' => $text, 'twitter_password' => $text,
            'youtube_url' => $text, 'youtube_access' => $yesNo,
            'other_platforms' => $long,

            'website_cms' => $text, 'website_admin_url' => $text, 'website_username' => $text, 'website_password' => $text,
            'hosting_provider' => $text, 'domain_provider' => $text, 'website_notes' => $long,

            'brand_notes' => $long,
            'logo' => ['nullable', 'file', 'max:10240', 'mimes:'.self::FILE_TYPES],
            'brand_files' => ['nullable', 'array', 'max:10'],
            'brand_files.*' => ['file', 'max:20480', 'mimes:'.self::FILE_TYPES],

            'google_analytics' => [$req, Rule::in(['yes', 'no'])],
            'search_console' => [$req, Rule::in(['yes', 'no'])],
            'google_ads' => [$req, Rule::in(['yes', 'no'])],
            'google_business_url' => $text,

            'goals' => [$staff ? 'nullable' : 'required', 'array', $staff ? 'min:0' : 'min:1'],
            'goals.*' => [Rule::in($o['goals'])],
            'goals_other' => $text,
            'target_audience' => [$req, 'string', 'max:3000'],
            'challenges' => $long,
            'competitors' => $long,
            'notes' => $long,

            'address' => $long,
            'city' => $text,
            'state' => ['nullable', Rule::in([...array_values(Gst::STATES), 'Outside India'])],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
        ], [
            'gstin.regex' => 'Enter a valid 15-character GSTIN, or leave it empty.',
            'goals.required' => 'Choose at least one marketing goal.',
            'google_analytics.required' => 'Please answer this question.',
            'search_console.required' => 'Please answer this question.',
            'google_ads.required' => 'Please answer this question.',
            '*.mimes' => 'Upload an image, PDF, AI/EPS/PSD or ZIP file.',
            'logo.max' => 'The logo must be under 10 MB.',
            'brand_files.*.max' => 'Each brand file must be under 20 MB.',
        ]);
    }

    /**
     * Save answers onto the client record. Logins already on file are kept
     * unless new ones are typed in.
     */
    public function save(Client $client, array $data, Request $request, ?User $by): Client
    {
        $existing = $client->credentials ?? [];
        $typed = [
            'instagram' => array_filter(['handle' => $data['instagram_handle'] ?? null, 'login' => $data['instagram_email'] ?? null, 'password' => $data['instagram_password'] ?? null]),
            'twitter' => array_filter(['handle' => $data['twitter_handle'] ?? null, 'login' => $data['twitter_email'] ?? null, 'password' => $data['twitter_password'] ?? null]),
            'website' => array_filter(['admin_url' => $data['website_admin_url'] ?? null, 'login' => $data['website_username'] ?? null, 'password' => $data['website_password'] ?? null]),
        ];
        $credentials = $existing;
        foreach ($typed as $platform => $c) {
            if (isset($c['login']) || isset($c['password'])) {
                $credentials[$platform] = $c + ($existing[$platform] ?? []);
            }
        }

        $answers = Arr::except($data, [...self::SECRET, 'logo', 'brand_files']);
        $answers['shared_logins'] = array_keys($credentials); // which platforms have logins on file (not the logins)

        $client->update(array_filter([
            'company_name' => $data['business_name'],
            'contact_name' => $data['full_name'] ?? null,
            'email' => $client->email ?? ($data['email'] ?? null),
            'phone' => $data['phone'] ?? null,
            'website' => $data['website'] ?? null,
            'industry' => $data['industry'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'gstin' => $data['gstin'] ?? null,
        ], fn ($v) => $v !== null && $v !== '') + [
            'onboarding_data' => $answers,
            'credentials' => $credentials ?: null,
            'onboarded_at' => $client->onboarded_at ?? now(),
        ]);

        foreach ([['logo', [$request->file('logo')]], ['brand_asset', $request->file('brand_files', [])]] as [$kind, $files]) {
            foreach (array_filter($files) as $file) {
                $this->storeFile($client, $kind, $file, $by);
            }
        }

        return $client;
    }

    /** Answers for an edit form: everything except stored logins. */
    public function prefill(Client $client): array
    {
        $o = $client->onboarding_data ?? [];

        return array_merge(Arr::except($o, ['shared_logins']), array_filter([
            'business_name' => $client->company_name,
            'full_name' => $o['full_name'] ?? $client->contact_name,
            'email' => $o['email'] ?? $client->email,
            'phone' => $o['phone'] ?? $client->phone,
            'website' => $client->website,
            'industry' => $client->industry,
            'address' => $client->address,
            'city' => $client->city,
            'state' => $client->state,
            'gstin' => $client->gstin,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    private function storeFile(Client $client, string $kind, UploadedFile $file, ?User $by): void
    {
        $path = $file->store("clients/{$client->id}", 'local'); // storage/app/private, never public
        $client->files()->create([
            'kind' => $kind,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $by?->id,
        ]);
    }
}

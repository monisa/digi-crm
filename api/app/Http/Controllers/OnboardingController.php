<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\Billing\Gst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * "Digital Marketing Client Onboarding Form": new clients fill it in on first
 * login; the account unlocks when it is submitted. Shared passwords are kept
 * encrypted and separate from the other answers.
 */
class OnboardingController extends Controller
{
    /** Passwords / logins that go into the encrypted credentials store, not the answers. */
    private const SECRET = ['instagram_email', 'instagram_password', 'twitter_email', 'twitter_password', 'website_username', 'website_password'];

    private const FILE_TYPES = 'png,jpg,jpeg,webp,svg,pdf,ai,eps,psd,zip';

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'options' => config('crm.onboarding') + ['states' => [...array_values(Gst::STATES), 'Outside India']],
            'prefill' => [
                'full_name' => $user->name,
                'business_name' => $user->client?->company_name ?? $user->company_name,
                'email' => $user->email,
                'phone' => $user->phone ?? $user->client?->phone,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->needsOnboarding()) {
            return response()->json(['message' => 'Onboarding already completed.'], 409);
        }

        $yesNo = ['nullable', Rule::in(['yes', 'no'])];
        $text = ['nullable', 'string', 'max:255'];
        $long = ['nullable', 'string', 'max:3000'];
        $o = config('crm.onboarding');

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'business_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{8,20}$/'],
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

            'google_analytics' => ['required', Rule::in(['yes', 'no'])],
            'search_console' => ['required', Rule::in(['yes', 'no'])],
            'google_ads' => ['required', Rule::in(['yes', 'no'])],
            'google_business_url' => $text,

            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => [Rule::in($o['goals'])],
            'goals_other' => $text,
            'target_audience' => ['required', 'string', 'max:3000'],
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

        $credentials = array_filter([
            'instagram' => array_filter(['handle' => $data['instagram_handle'] ?? null, 'login' => $data['instagram_email'] ?? null, 'password' => $data['instagram_password'] ?? null]),
            'twitter' => array_filter(['handle' => $data['twitter_handle'] ?? null, 'login' => $data['twitter_email'] ?? null, 'password' => $data['twitter_password'] ?? null]),
            'website' => array_filter(['admin_url' => $data['website_admin_url'] ?? null, 'login' => $data['website_username'] ?? null, 'password' => $data['website_password'] ?? null]),
        ], fn ($c) => isset($c['login']) || isset($c['password']));

        $answers = Arr::except($data, [...self::SECRET, 'logo', 'brand_files']);
        $answers['shared_logins'] = array_keys($credentials); // which platforms have logins on file (not the logins)

        DB::transaction(function () use ($user, $data, $answers, $credentials, $request) {
            $client = $user->client ?? Client::create(['company_name' => $data['business_name'], 'status' => 'active']);
            $client->update(array_filter([
                'company_name' => $data['business_name'],
                'contact_name' => $data['full_name'],
                'email' => $client->email ?? $data['email'],
                'phone' => $data['phone'],
                'website' => $data['website'] ?? null,
                'industry' => $data['industry'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'gstin' => $data['gstin'] ?? null,
            ], fn ($v) => $v !== null) + ['onboarding_data' => $answers, 'credentials' => $credentials ?: null]);

            foreach ([['logo', [$request->file('logo')]], ['brand_asset', $request->file('brand_files', [])]] as [$kind, $files]) {
                foreach (array_filter($files) as $file) {
                    $this->storeFile($client, $kind, $file, $user);
                }
            }

            $user->forceFill([
                'name' => $data['full_name'],
                'phone' => $data['phone'],
                'client_id' => $client->id,
                'onboarding_data' => null, // answers live on the client record
                'onboarded_at' => now(),
                'status' => User::STATUS_ACTIVE,
            ])->save();
        });

        return response()->json(['message' => 'Thank you! Your account is now active.', 'user' => $user->fresh()->toAuthArray()]);
    }

    private function storeFile(Client $client, string $kind, UploadedFile $file, User $by): void
    {
        $path = $file->store("clients/{$client->id}", 'local'); // storage/app/private, never public
        $client->files()->create([
            'kind' => $kind,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
    }
}

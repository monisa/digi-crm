<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp Business Cloud API (Meta). The number's ID, the business account ID and a
 * system-user token are saved by the admin in Settings → Integrations (token encrypted).
 */
class WhatsApp
{
    private const KEY = 'whatsapp';

    /** @return array{phone_number_id?: string, waba_id?: string, token?: string, display_phone?: string, name?: string} */
    public function settings(): array
    {
        $s = (array) Setting::get(self::KEY, []);
        if (! empty($s['token'])) {
            try {
                $s['token'] = Crypt::decryptString($s['token']);
            } catch (\Throwable) {
                $s['token'] = null;
            }
        }

        return $s;
    }

    public function configured(): bool
    {
        $s = $this->settings();

        return ! empty($s['phone_number_id']) && ! empty($s['waba_id']) && ! empty($s['token']);
    }

    /** Check the details with Meta, subscribe the app to the account's webhooks, then save. */
    public function connect(string $phoneNumberId, string $wabaId, string $token): array
    {
        $info = $this->call('get', $phoneNumberId, ['fields' => 'display_phone_number,verified_name'], $token);
        $this->call('post', "$wabaId/subscribed_apps", [], $token);
        $saved = [
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'token' => Crypt::encryptString($token),
            'display_phone' => $info['display_phone_number'] ?? null,
            'name' => $info['verified_name'] ?? null,
            'connected_at' => now()->toIso8601String(),
        ];
        Setting::put(self::KEY, $saved);
        Cache::forget('wa.templates');

        return ['display_phone' => $saved['display_phone'], 'name' => $saved['name']];
    }

    public function disconnect(): void
    {
        Setting::where('key', self::KEY)->delete();
        Cache::forget('wa.templates');
    }

    /**
     * Approved message templates, simplified for the app: the text with {{1}}… and how many
     * values each part needs. Templates with media headers or link variables are left out.
     */
    public function templates(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('wa.templates');
        }

        return Cache::remember('wa.templates', 600, function () {
            $s = $this->settings();
            $r = $this->call('get', $s['waba_id'].'/message_templates', ['fields' => 'name,language,status,category,components', 'limit' => 200]);
            $out = [];
            foreach ($r['data'] ?? [] as $t) {
                if (($t['status'] ?? '') !== 'APPROVED') {
                    continue;
                }
                $header = null;
                $body = '';
                $footer = null;
                $ok = true;
                foreach ($t['components'] ?? [] as $c) {
                    $type = strtoupper($c['type'] ?? '');
                    if ($type === 'HEADER') {
                        if (strtoupper($c['format'] ?? 'TEXT') !== 'TEXT') {
                            $ok = false;
                        }
                        $header = $c['text'] ?? null;
                    } elseif ($type === 'BODY') {
                        $body = $c['text'] ?? '';
                    } elseif ($type === 'FOOTER') {
                        $footer = $c['text'] ?? null;
                    } elseif ($type === 'BUTTONS') {
                        foreach ($c['buttons'] ?? [] as $b) {
                            if (str_contains((string) ($b['url'] ?? ''), '{{')) {
                                $ok = false;
                            }
                        }
                    }
                }
                if (! $ok || $body === '' || preg_match('/\{\{[a-z_]+\}\}/i', $header.$body)) {
                    continue; // media headers, link variables or named variables: not supported here
                }
                $out[] = [
                    'name' => $t['name'],
                    'language' => $t['language'] ?? 'en',
                    'category' => $t['category'] ?? null,
                    'header' => $header,
                    'body' => $body,
                    'footer' => $footer,
                    'header_vars' => self::vars($header),
                    'body_vars' => self::vars($body),
                ];
            }

            return $out;
        });
    }

    /** Free text (only allowed within 24 hours of the person's last message). Returns Meta's message id. */
    public function sendText(string $to, string $text): string
    {
        return $this->send($to, ['type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]]);
    }

    /** An approved template with its {{1}}… values filled in. Returns [message id, the text as sent]. */
    public function sendTemplate(string $to, array $template, array $headerValues, array $bodyValues): array
    {
        $components = [];
        if ($template['header_vars']) {
            $components[] = ['type' => 'header', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $headerValues)];
        }
        if ($template['body_vars']) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $bodyValues)];
        }
        $id = $this->send($to, ['type' => 'template', 'template' => array_filter([
            'name' => $template['name'],
            'language' => ['code' => $template['language']],
            'components' => $components ?: null,
        ])]);
        $text = trim(implode("\n\n", array_filter([self::fill($template['header'], $headerValues), self::fill($template['body'], $bodyValues), $template['footer']])));

        return [$id, $text];
    }

    /** "+91 98765 43210", "098765 43210" or "9876543210" → "919876543210" (India by default). */
    public static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $d = '91'.$d;
        }

        return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
    }

    public static function fill(?string $text, array $values): ?string
    {
        if ($text === null) {
            return null;
        }

        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => (string) ($values[(int) $m[1] - 1] ?? $m[0]), $text);
    }

    private static function vars(?string $text): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', (string) $text, $m);

        return $m[1] ? max(array_map('intval', $m[1])) : 0;
    }

    private function send(string $to, array $message): string
    {
        $s = $this->settings();
        $r = $this->call('post', $s['phone_number_id'].'/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, ...$message]);
        $id = $r['messages'][0]['id'] ?? null;
        if (! $id) {
            throw new RuntimeException('WhatsApp did not accept the message.');
        }

        return $id;
    }

    private function call(string $method, string $path, array $data = [], ?string $token = null): array
    {
        $token ??= $this->settings()['token'] ?? null;
        if (! $token) {
            throw new RuntimeException('WhatsApp is not connected yet (Settings → Integrations).');
        }
        $url = rtrim(config('services.meta.graph_url', 'https://graph.facebook.com'), '/').'/'.config('services.meta.graph_version', 'v21.0').'/'.$path;
        /** @var Response $r */
        $r = Http::withToken($token)->acceptJson()->timeout(20)->{$method}($url, $data);
        if ($r->failed()) {
            $e = $r->json('error') ?? [];
            $msg = $e['error_user_msg'] ?? $e['message'] ?? ('HTTP '.$r->status());
            throw new RuntimeException(self::friendly((int) ($e['code'] ?? 0), $msg));
        }

        return $r->json() ?? [];
    }

    /** Meta's most common errors, in plain words. */
    private static function friendly(int $code, string $msg): string
    {
        return match ($code) {
            131047 => 'More than 24 hours since their last message: send an approved template instead.',
            131026 => 'This number is not on WhatsApp, or cannot receive messages.',
            131030 => 'This number is not in the allowed test list (add it in Meta → WhatsApp → API Setup, or go live).',
            190 => 'The WhatsApp access token has expired or is wrong. Reconnect in Settings → Integrations.',
            132001 => 'This template does not exist in that language (or is not approved yet).',
            131056 => 'Too many messages to this number in a short time. Try again in a minute.',
            default => $msg,
        };
    }
}

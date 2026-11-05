<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_sends_safe_headers_and_only_allows_the_crm_site(): void
    {
        config(['cors.allowed_origins' => ['https://crm.digifoxprosolutions.com']]);
        $r = $this->getJson('/v1/health', ['Origin' => 'https://crm.digifoxprosolutions.com'])->assertOk();
        $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Access-Control-Allow-Origin', 'https://crm.digifoxprosolutions.com');
        $this->assertStringContainsString("default-src 'none'", $r->headers->get('Content-Security-Policy'));

        $evil = $this->getJson('/v1/health', ['Origin' => 'https://evil.example']);
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin')); // browser refuses it
    }
}

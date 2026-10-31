<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/v1/health')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_auth_me_requires_login(): void
    {
        $this->getJson('/v1/auth/me')->assertUnauthorized();
    }

    public function test_unknown_routes_return_json(): void
    {
        $this->get('/v1/does-not-exist')->assertNotFound()->assertHeader('content-type', 'application/json');
    }
}

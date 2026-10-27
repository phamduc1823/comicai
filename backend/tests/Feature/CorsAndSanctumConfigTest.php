<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsAndSanctumConfigTest extends TestCase
{
    /**
     * Test GET /sanctum/csrf-cookie sets CSRF cookie and allows frontend origin with credentials.
     */
    public function test_csrf_cookie_endpoint_supports_frontend_origin_with_credentials(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
        ])->get('/sanctum/csrf-cookie');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertCookie('XSRF-TOKEN');
    }

    /**
     * Test preflight OPTIONS request for API endpoints from allowed origin.
     */
    public function test_preflight_options_allowed_for_valid_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-XSRF-TOKEN, Content-Type',
        ])->options('/api/health');

        $response->assertStatus(204);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Test preflight OPTIONS request from disallowed/arbitrary origin is rejected.
     */
    public function test_preflight_options_rejected_for_disallowed_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://malicious-site.com',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/health');

        // Disallowed origin must not receive Access-Control-Allow-Origin
        $this->assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'Disallowed origin must not be granted Access-Control-Allow-Origin header.'
        );
    }

    /**
     * Test API request with 127.0.0.1:5173 origin is also supported.
     */
    public function test_api_endpoint_supports_ip_based_frontend_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
        ])->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Test Sanctum stateful domains include expected dev origins and contain no malformed strings or duplicates.
     */
    public function test_sanctum_stateful_domains_configured_for_vite(): void
    {
        $stateful = config('sanctum.stateful');

        $this->assertIsArray($stateful);
        $this->assertContains('localhost:5173', $stateful);
        $this->assertContains('127.0.0.1:5173', $stateful);
        $this->assertContains('localhost', $stateful);
        $this->assertContains('127.0.0.1', $stateful);
        $this->assertContains('::1', $stateful);

        // Assert no element contains malformed concatenated hostnames or unexploded commas
        foreach ($stateful as $domain) {
            $this->assertStringNotContainsString('::1localhost', $domain, 'Domain must not concatenate ::1 with localhost.');
            $this->assertStringNotContainsString(',', $domain, 'Domain element must not contain unexploded commas.');
            $this->assertNotEmpty($domain, 'Domain element must not be empty.');
        }

        // Assert deduplication: each domain occurs exactly once
        $this->assertSame(array_unique($stateful), $stateful, 'Stateful domains must not contain duplicate entries.');
    }

    /**
     * Test Sanctum stateful domains fallback behavior when SANCTUM_STATEFUL_DOMAINS env is unset.
     */
    public function test_sanctum_stateful_domains_fallback_without_env_variable(): void
    {
        $originalGetenv = getenv('SANCTUM_STATEFUL_DOMAINS');
        $hasEnv = array_key_exists('SANCTUM_STATEFUL_DOMAINS', $_ENV);
        $originalEnv = $_ENV['SANCTUM_STATEFUL_DOMAINS'] ?? null;
        $hasServer = array_key_exists('SANCTUM_STATEFUL_DOMAINS', $_SERVER);
        $originalServer = $_SERVER['SANCTUM_STATEFUL_DOMAINS'] ?? null;

        try {
            putenv('SANCTUM_STATEFUL_DOMAINS');
            unset($_ENV['SANCTUM_STATEFUL_DOMAINS'], $_SERVER['SANCTUM_STATEFUL_DOMAINS']);

            $sanctumConfig = require config_path('sanctum.php');
            $fallbackStateful = $sanctumConfig['stateful'];

            $this->assertIsArray($fallbackStateful);
            $this->assertContains('localhost:5173', $fallbackStateful);
            $this->assertContains('127.0.0.1:5173', $fallbackStateful);
            $this->assertContains('localhost', $fallbackStateful);
            $this->assertContains('127.0.0.1', $fallbackStateful);
            $this->assertContains('::1', $fallbackStateful);

            foreach ($fallbackStateful as $domain) {
                $this->assertStringNotContainsString('::1localhost', $domain);
                $this->assertStringNotContainsString(',', $domain);
                $this->assertNotEmpty($domain);
            }

            $this->assertSame(array_unique($fallbackStateful), $fallbackStateful, 'Fallback domains must be deduplicated.');
        } finally {
            if ($originalGetenv !== false) {
                putenv("SANCTUM_STATEFUL_DOMAINS={$originalGetenv}");
            } else {
                putenv('SANCTUM_STATEFUL_DOMAINS');
            }

            if ($hasEnv) {
                $_ENV['SANCTUM_STATEFUL_DOMAINS'] = $originalEnv;
            } else {
                unset($_ENV['SANCTUM_STATEFUL_DOMAINS']);
            }

            if ($hasServer) {
                $_SERVER['SANCTUM_STATEFUL_DOMAINS'] = $originalServer;
            } else {
                unset($_SERVER['SANCTUM_STATEFUL_DOMAINS']);
            }
        }
    }

    /**
     * Test Sanctum stateful domains fallback appends custom APP_URL with host and port.
     */
    public function test_sanctum_stateful_domains_fallback_with_custom_app_url(): void
    {
        $originalGetenv = getenv('SANCTUM_STATEFUL_DOMAINS');
        $hasEnv = array_key_exists('SANCTUM_STATEFUL_DOMAINS', $_ENV);
        $originalEnv = $_ENV['SANCTUM_STATEFUL_DOMAINS'] ?? null;
        $hasServer = array_key_exists('SANCTUM_STATEFUL_DOMAINS', $_SERVER);
        $originalServer = $_SERVER['SANCTUM_STATEFUL_DOMAINS'] ?? null;
        $originalAppUrl = config('app.url');

        try {
            putenv('SANCTUM_STATEFUL_DOMAINS');
            unset($_ENV['SANCTUM_STATEFUL_DOMAINS'], $_SERVER['SANCTUM_STATEFUL_DOMAINS']);

            config(['app.url' => 'https://staging.comicai.com:8443']);

            $sanctumConfig = require config_path('sanctum.php');
            $fallbackStateful = $sanctumConfig['stateful'];

            $this->assertIsArray($fallbackStateful);
            $this->assertContains('staging.comicai.com:8443', $fallbackStateful);
            $this->assertContains('localhost:5173', $fallbackStateful);
            $this->assertContains('127.0.0.1:5173', $fallbackStateful);
            $this->assertContains('localhost', $fallbackStateful);
            $this->assertContains('127.0.0.1', $fallbackStateful);
            $this->assertContains('::1', $fallbackStateful);

            foreach ($fallbackStateful as $domain) {
                $this->assertStringNotContainsString('::1localhost', $domain);
                $this->assertStringNotContainsString('::1staging', $domain);
                $this->assertStringNotContainsString(',', $domain);
                $this->assertNotEmpty($domain);
            }

            $this->assertSame(array_unique($fallbackStateful), $fallbackStateful, 'Domains must be cleanly deduplicated.');
        } finally {
            config(['app.url' => $originalAppUrl]);

            if ($originalGetenv !== false) {
                putenv("SANCTUM_STATEFUL_DOMAINS={$originalGetenv}");
            } else {
                putenv('SANCTUM_STATEFUL_DOMAINS');
            }

            if ($hasEnv) {
                $_ENV['SANCTUM_STATEFUL_DOMAINS'] = $originalEnv;
            } else {
                unset($_ENV['SANCTUM_STATEFUL_DOMAINS']);
            }

            if ($hasServer) {
                $_SERVER['SANCTUM_STATEFUL_DOMAINS'] = $originalServer;
            } else {
                unset($_SERVER['SANCTUM_STATEFUL_DOMAINS']);
            }
        }
    }
}

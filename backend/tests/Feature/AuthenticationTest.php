<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected string $origin = 'http://localhost:5173';

    /**
     * Helper to make SPA JSON requests with appropriate Origin header and cookie handling.
     */
    protected function spaJson(string $method, string $uri, array $data = [], array $cookies = [])
    {
        $this->app['auth']->forgetGuards();

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        $test = $this->withHeaders([
            'Origin' => $this->origin,
            'Accept' => 'application/json',
        ])->withCredentials();

        if (! empty($cookies)) {
            $test->withCookies($cookies);
        }

        return $test->json($method, $uri, $data);
    }

    /**
     * 1. Test user can register and is authenticated automatically with default 'reader' role.
     */
    public function test_user_can_register_and_is_authenticated_automatically(): void
    {
        $response = $this->spaJson('POST', '/api/register', [
            'name' => 'Test Reader',
            'email' => 'reader@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Đăng ký tài khoản thành công.',
            'user' => [
                'name' => 'Test Reader',
                'email' => 'reader@example.com',
                'role' => 'reader',
                'is_active' => true,
            ],
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'reader@example.com',
            'role' => 'reader',
            'is_active' => 1,
        ]);

        // Verify session cookie was emitted
        $cookieName = config('session.cookie');
        $sessionCookie = $response->getCookie($cookieName);
        $this->assertNotNull($sessionCookie, 'Registration must return a session cookie.');

        // Verify newly registered user can immediately access protected /api/user with the session cookie
        $userResponse = $this->spaJson('GET', '/api/user', [], [
            $cookieName => $sessionCookie->getValue(),
        ]);
        $userResponse->assertStatus(200);
        $userResponse->assertJsonPath('user.email', 'reader@example.com');
    }

    /**
     * 2. Test registration validation fails with invalid data.
     */
    public function test_register_validation_fails_with_invalid_data(): void
    {
        $response = $this->spaJson('POST', '/api/register', [
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    /**
     * 3. Test registration fails when email is already taken.
     */
    public function test_register_fails_when_email_already_exists(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->spaJson('POST', '/api/register', [
            'name' => 'Another User',
            'email' => 'existing@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * 4. Test registration ignores client-provided role and is_active (anti-privilege escalation).
     */
    public function test_register_ignores_client_provided_role_and_is_active(): void
    {
        $response = $this->spaJson('POST', '/api/register', [
            'name' => 'Escalation Attempt',
            'email' => 'escalate@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',
            'is_active' => false,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.role', 'reader');
        $response->assertJsonPath('user.is_active', true);

        $this->assertDatabaseHas('users', [
            'email' => 'escalate@example.com',
            'role' => 'reader',
            'is_active' => 1,
        ]);
    }

    /**
     * 5. Test user can login with valid credentials and session is established.
     */
    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        $response = $this->spaJson('POST', '/api/login', [
            'email' => 'login@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Đăng nhập thành công.',
            'user' => [
                'id' => $user->id,
                'email' => 'login@example.com',
                'role' => 'reader',
            ],
        ]);

        $cookieName = config('session.cookie');
        $this->assertNotNull($response->getCookie($cookieName), 'Login response must return session cookie.');
    }

    /**
     * 6. Test login fails with unified error for non-existent email (prevents enumeration).
     */
    public function test_login_fails_with_unified_error_for_nonexistent_email(): void
    {
        $response = $this->spaJson('POST', '/api/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertSame(
            'Thông tin đăng nhập không chính xác hoặc tài khoản không khả dụng.',
            $response->json('errors.email.0')
        );
    }

    /**
     * 7. Test login fails with unified error for wrong password (prevents enumeration).
     */
    public function test_login_fails_with_unified_error_for_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'valid@example.com',
            'password' => Hash::make('CorrectPassword123!'),
            'is_active' => true,
        ]);

        $response = $this->spaJson('POST', '/api/login', [
            'email' => 'valid@example.com',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertSame(
            'Thông tin đăng nhập không chính xác hoặc tài khoản không khả dụng.',
            $response->json('errors.email.0')
        );
    }

    /**
     * 8. Test login fails with unified error for inactive account (prevents enumeration).
     */
    public function test_login_fails_with_unified_error_for_inactive_account(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('CorrectPassword123!'),
            'is_active' => false,
        ]);

        $response = $this->spaJson('POST', '/api/login', [
            'email' => 'inactive@example.com',
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertSame(
            'Thông tin đăng nhập không chính xác hoặc tài khoản không khả dụng.',
            $response->json('errors.email.0')
        );
    }

    /**
     * 9. Test logged-in user calling POST /api/login receives HTTP 400 JSON without 302 redirect.
     */
    public function test_logged_in_user_cannot_call_login_again(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $loginResponse = $this->spaJson('POST', '/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);
        $cookieName = config('session.cookie');
        $sessionCookie = $loginResponse->getCookie($cookieName);

        // Attempt second login with active session
        $secondResponse = $this->spaJson('POST', '/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ], [
            $cookieName => $sessionCookie->getValue(),
        ]);

        $secondResponse->assertStatus(400);
        $secondResponse->assertJson([
            'status' => 'error',
            'message' => 'Bạn đã đăng nhập rồi.',
        ]);
    }

    /**
     * 10. Test logged-in user calling POST /api/register receives HTTP 400 JSON without 302 redirect.
     */
    public function test_logged_in_user_cannot_call_register_again(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $loginResponse = $this->spaJson('POST', '/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);
        $cookieName = config('session.cookie');
        $sessionCookie = $loginResponse->getCookie($cookieName);

        // Attempt register with active session
        $registerResponse = $this->spaJson('POST', '/api/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], [
            $cookieName => $sessionCookie->getValue(),
        ]);

        $registerResponse->assertStatus(400);
        $registerResponse->assertJson([
            'status' => 'error',
            'message' => 'Bạn đã đăng nhập rồi.',
        ]);
    }

    /**
     * 11. Test authenticated active user can retrieve profile via GET /api/user.
     */
    public function test_authenticated_active_user_can_get_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Profile User',
            'email' => 'profile@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $loginResponse = $this->spaJson('POST', '/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);
        $cookieName = config('session.cookie');
        $sessionCookie = $loginResponse->getCookie($cookieName);

        $profileResponse = $this->spaJson('GET', '/api/user', [], [
            $cookieName => $sessionCookie->getValue(),
        ]);

        $profileResponse->assertStatus(200);
        $profileResponse->assertJson([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'name' => 'Profile User',
                'email' => 'profile@example.com',
                'role' => 'reader',
                'is_active' => true,
            ],
        ]);
    }

    /**
     * 12. Test unauthenticated user cannot access protected routes.
     */
    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        $userResponse = $this->spaJson('GET', '/api/user');
        $userResponse->assertStatus(401);
        $userResponse->assertJson(['message' => 'Unauthenticated.']);

        $logoutResponse = $this->spaJson('POST', '/api/logout');
        $logoutResponse->assertStatus(401);
        $logoutResponse->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 13. Test session cookie lifecycle and immediate blocking upon account deactivation:
     * - Login active user, obtain session cookie.
     * - Send request to /api/user with session cookie, verify HTTP 200.
     * - Deactivate user in database (is_active = false).
     * - Send subsequent request with old session cookie, verify HTTP 403.
     * - Send next request with that same cookie, verify HTTP 401 (session invalidated on server).
     */
    public function test_session_cookie_lifecycle_and_immediate_blocking_upon_deactivation(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $loginResponse = $this->spaJson('POST', '/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);
        $cookieName = config('session.cookie');
        $sessionCookie = $loginResponse->getCookie($cookieName);
        $this->assertNotNull($sessionCookie);

        $cookies = [$cookieName => $sessionCookie->getValue()];

        // Request 1: Active session -> HTTP 200
        $activeResponse = $this->spaJson('GET', '/api/user', [], $cookies);
        $activeResponse->assertStatus(200);

        // Deactivate user in database (forceFill because is_active is guarded from mass-assignment)
        $user->forceFill(['is_active' => false])->save();

        // Request 2: Send request with old session cookie -> blocked immediately with HTTP 403
        $blockedResponse = $this->spaJson('GET', '/api/user', [], $cookies);
        $blockedResponse->assertStatus(403);
        $blockedResponse->assertJson([
            'status' => 'error',
            'message' => 'Tài khoản của bạn đã bị vô hiệu hóa.',
        ]);

        // Request 3: Subsequent request with that same cookie -> session was invalidated -> HTTP 401
        $subsequentResponse = $this->spaJson('GET', '/api/user', [], $cookies);
        $subsequentResponse->assertStatus(401);
        $subsequentResponse->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 14. Test user can logout and session cookie is invalidated on the server.
     */
    public function test_user_can_logout_and_session_is_invalidated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $loginResponse = $this->spaJson('POST', '/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);
        $cookieName = config('session.cookie');
        $sessionCookie = $loginResponse->getCookie($cookieName);
        $cookies = [$cookieName => $sessionCookie->getValue()];

        // Logout with session cookie
        $logoutResponse = $this->spaJson('POST', '/api/logout', [], $cookies);
        $logoutResponse->assertStatus(200);
        $logoutResponse->assertJson([
            'status' => 'success',
            'message' => 'Đăng xuất thành công.',
        ]);

        // Accessing protected route with old session cookie must now be rejected
        $afterLogoutResponse = $this->spaJson('GET', '/api/user', [], $cookies);
        $afterLogoutResponse->assertStatus(401);
        $afterLogoutResponse->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 15. Test authentication responses never expose passwords, password hashes, or remember tokens.
     */
    public function test_auth_responses_never_expose_passwords_hashes_or_remember_tokens(): void
    {
        $registerResponse = $this->spaJson('POST', '/api/register', [
            'name' => 'Security Check',
            'email' => 'security_reg@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $registerResponse->assertStatus(201);

        $cookieName = config('session.cookie');
        $regCookie = $registerResponse->getCookie($cookieName);

        // Log out so subsequent login is allowed by EnsureGuestApi
        $this->spaJson('POST', '/api/logout', [], [
            $cookieName => $regCookie->getValue(),
        ])->assertStatus(200);

        User::factory()->create([
            'email' => 'security_login@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
        ]);

        $loginResponse = $this->spaJson('POST', '/api/login', [
            'email' => 'security_login@example.com',
            'password' => 'Password123!',
        ]);
        $loginResponse->assertStatus(200);

        $loginCookie = $loginResponse->getCookie($cookieName);

        $userResponse = $this->spaJson('GET', '/api/user', [], [
            $cookieName => $loginCookie->getValue(),
        ]);
        $userResponse->assertStatus(200);

        foreach ([$registerResponse, $loginResponse, $userResponse] as $res) {
            $userPayload = $res->json('user');
            $this->assertIsArray($userPayload);
            $this->assertArrayNotHasKey('password', $userPayload);
            $this->assertArrayNotHasKey('remember_token', $userPayload);

            $rawContent = $res->getContent();
            $this->assertStringNotContainsString('Password123!', $rawContent);
            $this->assertStringNotContainsString('$2y$', $rawContent); // bcrypt signature
        }
    }

    /**
     * 16. Test rate limiting throttles excessive login attempts (5 attempts/min) with dedicated IP.
     */
    public function test_rate_limiting_throttles_excessive_login_attempts(): void
    {
        $testIp = '203.0.113.199'; // Dedicated TEST-NET IP isolating rate limit counter

        try {
            // First 5 attempts return validation failure (422)
            for ($i = 0; $i < 5; $i++) {
                $response = $this->withHeaders([
                    'Origin' => $this->origin,
                    'Accept' => 'application/json',
                ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                    ->postJson('/api/login', [
                        'email' => 'ratelimit@example.com',
                        'password' => 'wrong-pass',
                    ]);
                $response->assertStatus(422);
            }

            // 6th attempt from the same IP must be throttled with HTTP 429
            $throttledResponse = $this->withHeaders([
                'Origin' => $this->origin,
                'Accept' => 'application/json',
            ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->postJson('/api/login', [
                    'email' => 'ratelimit@example.com',
                    'password' => 'wrong-pass',
                ]);

            $throttledResponse->assertStatus(429);
            $throttledResponse->assertHeader('Retry-After');
            $this->assertNotEmpty($throttledResponse->headers->get('Retry-After'));
        } finally {
            // Clear only the specific test IP limiter key with 'login' prefix without flushing cache
            app(\Illuminate\Cache\RateLimiter::class)->clear('login'.sha1('|'.$testIp));
        }
    }

    /**
     * 17. Test rate limiting throttles excessive register attempts (10 attempts/min) with dedicated IP.
     */
    public function test_rate_limiting_throttles_excessive_register_attempts(): void
    {
        $testIp = '203.0.113.198'; // Dedicated TEST-NET IP isolating register rate limit counter

        try {
            // First 10 attempts fail validation (422)
            for ($i = 0; $i < 10; $i++) {
                $response = $this->withHeaders([
                    'Origin' => $this->origin,
                    'Accept' => 'application/json',
                ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                    ->postJson('/api/register', [
                        'name' => '',
                        'email' => 'invalid-email',
                        'password' => 'short',
                    ]);
                $response->assertStatus(422);
            }

            // 11th attempt from the same IP must be throttled with HTTP 429
            $throttledResponse = $this->withHeaders([
                'Origin' => $this->origin,
                'Accept' => 'application/json',
            ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->postJson('/api/register', [
                    'name' => '',
                    'email' => 'invalid-email',
                    'password' => 'short',
                ]);

            $throttledResponse->assertStatus(429);
            $throttledResponse->assertHeader('Retry-After');
            $this->assertNotEmpty($throttledResponse->headers->get('Retry-After'));
        } finally {
            // Clear only the specific test IP limiter key with 'register' prefix without flushing cache
            app(\Illuminate\Cache\RateLimiter::class)->clear('register'.sha1('|'.$testIp));
        }
    }

    /**
     * 18. Test rate limiting for login and register operate on completely independent counters.
     */
    public function test_rate_limiting_login_and_register_are_independent(): void
    {
        $testIp = '203.0.113.197'; // Dedicated TEST-NET IP isolating cross-endpoint rate limit verification

        try {
            // Phase 1: Exhaust login rate limit (5 attempts)
            for ($i = 0; $i < 5; $i++) {
                $loginRes = $this->withHeaders([
                    'Origin' => $this->origin,
                    'Accept' => 'application/json',
                ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                    ->postJson('/api/login', [
                        'email' => 'exhaust_login@example.com',
                        'password' => 'wrong-pass',
                    ]);
                $loginRes->assertStatus(422);
            }

            // 6th login attempt is throttled (429)
            $loginThrottled = $this->withHeaders([
                'Origin' => $this->origin,
                'Accept' => 'application/json',
            ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->postJson('/api/login', [
                    'email' => 'exhaust_login@example.com',
                    'password' => 'wrong-pass',
                ]);
            $loginThrottled->assertStatus(429);

            // Phase 2: Verify /api/register from the SAME IP is NOT throttled and has its own independent quota
            for ($i = 0; $i < 10; $i++) {
                $registerRes = $this->withHeaders([
                    'Origin' => $this->origin,
                    'Accept' => 'application/json',
                ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                    ->postJson('/api/register', [
                        'name' => 'Independent Check',
                        'email' => 'invalid-email',
                        'password' => 'short',
                    ]);
                $registerRes->assertStatus(422);
            }

            // 11th register attempt is throttled (429)
            $registerThrottled = $this->withHeaders([
                'Origin' => $this->origin,
                'Accept' => 'application/json',
            ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->postJson('/api/register', [
                    'name' => 'Independent Check',
                    'email' => 'invalid-email',
                    'password' => 'short',
                ]);
            $registerThrottled->assertStatus(429);
            $registerThrottled->assertHeader('Retry-After');
            $this->assertNotEmpty($registerThrottled->headers->get('Retry-After'));
        } finally {
            // Clean up both keys for the test IP without flushing cache
            $limiter = app(\Illuminate\Cache\RateLimiter::class);
            $limiter->clear('login'.sha1('|'.$testIp));
            $limiter->clear('register'.sha1('|'.$testIp));
        }
    }

    /**
     * 19. Test register rate limit exhaustion does not block login attempts from the same IP.
     */
    public function test_rate_limiting_register_exhaustion_does_not_block_login(): void
    {
        $testIp = '203.0.113.196'; // Dedicated TEST-NET IP isolating reverse cross-endpoint verification

        try {
            // Phase 1: Exhaust register rate limit (10 attempts)
            for ($i = 0; $i < 10; $i++) {
                $registerRes = $this->withHeaders([
                    'Origin' => $this->origin,
                    'Accept' => 'application/json',
                ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                    ->postJson('/api/register', [
                        'name' => 'Reverse Check',
                        'email' => 'invalid-email',
                        'password' => 'short',
                    ]);
                $registerRes->assertStatus(422);
            }

            // 11th register attempt is throttled with HTTP 429 and Retry-After
            $registerThrottled = $this->withHeaders([
                'Origin' => $this->origin,
                'Accept' => 'application/json',
            ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->postJson('/api/register', [
                    'name' => 'Reverse Check',
                    'email' => 'invalid-email',
                    'password' => 'short',
                ]);
            $registerThrottled->assertStatus(429);
            $registerThrottled->assertHeader('Retry-After');
            $this->assertNotEmpty($registerThrottled->headers->get('Retry-After'));

            // Phase 2: Send login request with formatted but wrong credentials from the SAME IP
            // Must be processed by login limiter and return HTTP 422, NOT HTTP 429
            $loginRes = $this->withHeaders([
                'Origin' => $this->origin,
                'Accept' => 'application/json',
            ])->withServerVariables(['REMOTE_ADDR' => $testIp])
                ->postJson('/api/login', [
                    'email' => 'valid_format@example.com',
                    'password' => 'WrongPassword123!',
                ]);
            $loginRes->assertStatus(422);
            $loginRes->assertJsonValidationErrors(['email']);
            $this->assertSame(
                'Thông tin đăng nhập không chính xác hoặc tài khoản không khả dụng.',
                $loginRes->json('errors.email.0')
            );
        } finally {
            // Clean up both keys for the test IP without flushing cache
            $limiter = app(\Illuminate\Cache\RateLimiter::class);
            $limiter->clear('login'.sha1('|'.$testIp));
            $limiter->clear('register'.sha1('|'.$testIp));
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $creator;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Register dedicated temporary test routes for role authorization tests
        Route::middleware(['auth:sanctum', 'active', 'role:creator'])
            ->get('/api/test-role/creator-only', function () {
                return response()->json(['status' => 'ok', 'message' => 'creator_endpoint']);
            });

        Route::middleware(['auth:sanctum', 'active', 'role:admin'])
            ->get('/api/test-role/admin-only', function () {
                return response()->json(['status' => 'ok', 'message' => 'admin_endpoint']);
            });

        Route::middleware(['auth:sanctum', 'active', 'role:creator,admin'])
            ->get('/api/test-role/creator-or-admin', function () {
                return response()->json(['status' => 'ok', 'message' => 'multi_role_endpoint']);
            });

        Route::middleware(['auth:sanctum', 'active', 'role'])
            ->get('/api/test-role/no-roles-configured', function () {
                return response()->json(['status' => 'ok', 'message' => 'should_not_reach']);
            });

        // Seed users with respective roles
        $this->reader = User::create([
            'name' => 'Reader User',
            'email' => 'reader@example.com',
            'password' => 'Password123!',
        ]);
        // Default role is 'reader', is_active = true

        $this->creator = User::create([
            'name' => 'Creator User',
            'email' => 'creator@example.com',
            'password' => 'Password123!',
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'Password123!',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();
    }

    /**
     * 1. Unauthenticated user accessing role-protected route is denied with HTTP 401.
     */
    public function test_unauthenticated_user_cannot_access_role_protected_route(): void
    {
        $response = $this->getJson('/api/test-role/creator-only');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 2. Reader cannot access creator-only route, receiving HTTP 403 Forbidden.
     */
    public function test_reader_cannot_access_creator_only_route(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/test-role/creator-only');

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Bạn không có quyền thực hiện hành động này.',
        ]);
    }

    /**
     * 3. Reader cannot access admin-only route, receiving HTTP 403 Forbidden.
     */
    public function test_reader_cannot_access_admin_only_route(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/test-role/admin-only');

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Bạn không có quyền thực hiện hành động này.',
        ]);
    }

    /**
     * 4. Creator can access creator-only route successfully with HTTP 200.
     */
    public function test_creator_can_access_creator_only_route(): void
    {
        $response = $this->actingAs($this->creator)->getJson('/api/test-role/creator-only');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'message' => 'creator_endpoint',
        ]);
    }

    /**
     * 5. Creator cannot access admin-only route, receiving HTTP 403 Forbidden.
     */
    public function test_creator_cannot_access_admin_only_route(): void
    {
        $response = $this->actingAs($this->creator)->getJson('/api/test-role/admin-only');

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Bạn không có quyền thực hiện hành động này.',
        ]);
    }

    /**
     * 6. Admin can access admin-only route successfully with HTTP 200.
     */
    public function test_admin_can_access_admin_only_route(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/test-role/admin-only');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'message' => 'admin_endpoint',
        ]);
    }

    /**
     * 7. Admin can access creator route via administrative inheritance according to audit matrix.
     */
    public function test_admin_can_access_creator_route(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/test-role/creator-only');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'message' => 'creator_endpoint',
        ]);
    }

    /**
     * 8. Both creator and admin can access multi-role endpoint ('role:creator,admin').
     */
    public function test_both_creator_and_admin_can_access_multi_role_route(): void
    {
        $creatorRes = $this->actingAs($this->creator)->getJson('/api/test-role/creator-or-admin');
        $creatorRes->assertStatus(200);

        $adminRes = $this->actingAs($this->admin)->getJson('/api/test-role/creator-or-admin');
        $adminRes->assertStatus(200);

        $readerRes = $this->actingAs($this->reader)->getJson('/api/test-role/creator-or-admin');
        $readerRes->assertStatus(403);
    }

    /**
     * 9. User cannot elevate privileges by passing role parameter in query, body, or headers.
     */
    public function test_user_cannot_elevate_privileges_via_request_parameters(): void
    {
        // Reader attempting to spoof admin via query params
        $resQuery = $this->actingAs($this->reader)->getJson('/api/test-role/admin-only?role=admin');
        $resQuery->assertStatus(403);

        // Reader attempting to spoof admin via header
        $resHeader = $this->actingAs($this->reader)->getJson('/api/test-role/admin-only', [
            'X-Role' => 'admin',
            'Role' => 'admin',
        ]);
        $resHeader->assertStatus(403);

        // Reader attempting to spoof admin via body in POST
        Route::middleware(['auth:sanctum', 'active', 'role:admin'])
            ->post('/api/test-role/admin-post', function () {
                return response()->json(['status' => 'ok']);
            });

        $resPost = $this->actingAs($this->reader)->postJson('/api/test-role/admin-post', [
            'role' => 'admin',
        ]);
        $resPost->assertStatus(403);
    }

    /**
     * 10. User with unknown or modified invalid role is denied with HTTP 403.
     */
    public function test_user_with_invalid_role_is_denied(): void
    {
        $invalidUser = User::create([
            'name' => 'Hacker User',
            'email' => 'hacker@example.com',
            'password' => 'Password123!',
        ]);
        $invalidUser->role = 'superuser';
        $invalidUser->save();

        $resCreator = $this->actingAs($invalidUser)->getJson('/api/test-role/creator-only');
        $resCreator->assertStatus(403);

        $resAdmin = $this->actingAs($invalidUser)->getJson('/api/test-role/admin-only');
        $resAdmin->assertStatus(403);
    }

    /**
     * 11. Middleware configured with empty roles denies by default to avoid accidental open endpoints.
     */
    public function test_middleware_with_empty_roles_denies_by_default(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/test-role/no-roles-configured');

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Bạn không có quyền thực hiện hành động này.',
        ]);
    }
}

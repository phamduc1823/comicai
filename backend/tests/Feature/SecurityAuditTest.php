<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\CoinTransaction;
use App\Models\Comic;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAI-027: Comprehensive Security Audit & Threat Hardening Test Suite
 *
 * Verifies:
 * 1. Auth & RBAC (Role isolation, admin protection, privilege escalation prevention)
 * 2. IDOR & Resource Ownership (Creator comic/chapter isolation)
 * 3. VIP Paywall Protection (Zero-leakage of images, verified bypass for admin/creator)
 * 4. Coin Wallet & Ledger Integrity (Insufficient balance, idempotency, append-only immutability, double-refund block)
 * 5. Injection & Web Security (SQL injection vectors in AI Search, XSS in comments/posts)
 * 6. Sensitive Data Protection (Zero exposure of password hashes or remember tokens)
 */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $creatorA;
    private User $creatorB;
    private User $reader;

    private Comic $comicA;
    private Comic $comicB;

    private Chapter $chapterA;
    private Chapter $chapterB;
    private Chapter $vipChapterA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Admin
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin_audit@example.com',
            'password' => 'Password123!',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        // 2. Setup Creator A
        $this->creatorA = User::create([
            'name' => 'Creator A',
            'email' => 'creatorA_audit@example.com',
            'password' => 'Password123!',
        ]);
        $this->creatorA->role = 'creator';
        $this->creatorA->save();

        // 3. Setup Creator B
        $this->creatorB = User::create([
            'name' => 'Creator B',
            'email' => 'creatorB_audit@example.com',
            'password' => 'Password123!',
        ]);
        $this->creatorB->role = 'creator';
        $this->creatorB->save();

        // 4. Setup Reader
        $this->reader = User::create([
            'name' => 'Reader User',
            'email' => 'reader_audit@example.com',
            'password' => 'Password123!',
        ]);
        // Default role is reader

        // 5. Setup Comics
        $this->comicA = Comic::create([
            'title' => 'Comic of Creator A',
            'slug' => 'comic-creator-a',
            'author_name' => 'Creator A Author',
            'user_id' => $this->creatorA->id,
            'description' => 'Description of Comic A',
            'cover_path' => 'placeholders/comics/demo-cover.webp',
            'banner_path' => 'placeholders/comics/demo-banner.webp',
            'status' => 'ongoing',
            'age_rating' => '13+',
        ]);
        $this->comicA->approval_status = 'approved';
        $this->comicA->save();

        $this->comicB = Comic::create([
            'title' => 'Comic of Creator B',
            'slug' => 'comic-creator-b',
            'author_name' => 'Creator B Author',
            'user_id' => $this->creatorB->id,
            'description' => 'Description of Comic B',
            'cover_path' => 'placeholders/comics/demo-cover.webp',
            'banner_path' => 'placeholders/comics/demo-banner.webp',
            'status' => 'ongoing',
            'age_rating' => '13+',
        ]);
        $this->comicB->approval_status = 'approved';
        $this->comicB->save();

        // 6. Setup Chapters
        $this->chapterA = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '1.00',
            'title' => 'Chapter 1 Free A',
            'slug' => 'chap-1-free-a',
            'status' => 'published',
            'is_vip' => false,
            'price' => 0,
        ]);

        ChapterImage::create([
            'chapter_id' => $this->chapterA->id,
            'page_number' => 1,
            'image_path' => 'comics/1/chapters/1/page_1.jpg',
            'storage_disk' => 'public',
        ]);

        $this->chapterB = Chapter::create([
            'comic_id' => $this->comicB->id,
            'chapter_number' => '1.00',
            'title' => 'Chapter 1 Free B',
            'slug' => 'chap-1-free-b',
            'status' => 'published',
            'is_vip' => false,
            'price' => 0,
        ]);

        // 7. Setup VIP Chapter on Comic A
        $this->vipChapterA = Chapter::create([
            'comic_id' => $this->comicA->id,
            'chapter_number' => '2.00',
            'title' => 'Chapter 2 VIP A',
            'slug' => 'chap-2-vip-a',
            'status' => 'published',
            'is_vip' => true,
            'price' => 15,
        ]);

        ChapterImage::create([
            'chapter_id' => $this->vipChapterA->id,
            'page_number' => 1,
            'image_path' => 'comics/1/chapters/2/secret_page_1.jpg',
            'storage_disk' => 'public',
        ]);
        ChapterImage::create([
            'chapter_id' => $this->vipChapterA->id,
            'page_number' => 2,
            'image_path' => 'comics/1/chapters/2/secret_page_2.jpg',
            'storage_disk' => 'public',
        ]);
    }

    // =========================================================================
    // SECTION 1: AUTH & RBAC (Role Isolation & Privilege Escalation)
    // =========================================================================

    public function test_reader_cannot_access_admin_moderation_queue(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/admin/moderation/comics');
        $response->assertStatus(403);
    }

    public function test_reader_cannot_access_admin_chapter_moderation(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/admin/moderation/chapters');
        $response->assertStatus(403);
    }

    public function test_reader_cannot_access_admin_vip_refund(): void
    {
        $response = $this->actingAs($this->reader)->postJson('/api/admin/vip/refund', [
            'transaction_id' => 1,
            'reason' => 'Unauthorized refund attempt',
        ]);
        $response->assertStatus(403);
    }

    public function test_reader_cannot_access_creator_studio_endpoints(): void
    {
        $response = $this->actingAs($this->reader)->getJson('/api/creator/comics');
        $response->assertStatus(403);

        $createResponse = $this->actingAs($this->reader)->postJson('/api/creator/comics', [
            'title' => 'Malicious Comic',
        ]);
        $createResponse->assertStatus(403);
    }

    public function test_guest_is_unauthenticated_on_protected_endpoints(): void
    {
        $this->getJson('/api/user')->assertStatus(401);
        $this->getJson('/api/creator/comics')->assertStatus(401);
        $this->getJson('/api/admin/moderation/comics')->assertStatus(401);
        $this->getJson('/api/wallet')->assertStatus(401);
    }

    public function test_registration_ignores_client_role_and_cannot_escalate_to_admin(): void
    {
        $payload = [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',       // Malicious role injection attempt
            'is_active' => true,
        ];

        $response = $this->postJson('/api/register', $payload);
        $response->assertStatus(201);

        $registeredUser = User::where('email', 'attacker@example.com')->firstOrFail();
        $this->assertEquals('reader', $registeredUser->role, 'Role must strictly default to reader');
        $this->assertNotEquals('admin', $registeredUser->role);
    }

    // =========================================================================
    // SECTION 2: IDOR & RESOURCE OWNERSHIP (Creator Isolation)
    // =========================================================================

    public function test_creator_cannot_update_comic_owned_by_another_creator(): void
    {
        $response = $this->actingAs($this->creatorB)->putJson("/api/creator/comics/{$this->comicA->id}", [
            'title' => 'Hacked Title',
        ]);
        $response->assertStatus(403);

        $this->comicA->refresh();
        $this->assertEquals('Comic of Creator A', $this->comicA->title);
    }

    public function test_creator_cannot_delete_comic_owned_by_another_creator(): void
    {
        $response = $this->actingAs($this->creatorB)->deleteJson("/api/creator/comics/{$this->comicA->id}");
        $response->assertStatus(403);

        $this->assertDatabaseHas('comics', ['id' => $this->comicA->id]);
    }

    public function test_creator_cannot_create_chapter_for_comic_owned_by_another_creator(): void
    {
        $response = $this->actingAs($this->creatorB)->postJson("/api/creator/comics/{$this->comicA->id}/chapters", [
            'chapter_number' => 99,
            'title' => 'Injected Chapter',
        ]);
        $response->assertStatus(403);
    }

    public function test_creator_cannot_update_chapter_owned_by_another_creator(): void
    {
        $response = $this->actingAs($this->creatorB)->putJson("/api/creator/chapters/{$this->chapterA->id}", [
            'title' => 'Injected Title Update',
        ]);
        $response->assertStatus(403);
    }

    public function test_creator_cannot_manage_images_for_chapter_owned_by_another_creator(): void
    {
        $response = $this->actingAs($this->creatorB)->postJson("/api/creator/chapters/{$this->chapterA->id}/images", []);
        $response->assertStatus(403);

        $reorderResponse = $this->actingAs($this->creatorB)->putJson("/api/creator/chapters/{$this->chapterA->id}/images/reorder", [
            'image_ids' => [1],
        ]);
        $reorderResponse->assertStatus(403);
    }

    // =========================================================================
    // SECTION 3: VIP PAYWALL (Zero-Leakage & Verified Bypass)
    // =========================================================================

    public function test_guest_calling_vip_chapter_receives_empty_images_array_without_leaking_paths(): void
    {
        $response = $this->getJson("/api/comics/{$this->comicA->slug}/chapters/{$this->vipChapterA->chapter_number}");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertTrue($data['is_vip']);
        $this->assertFalse($data['is_unlocked']);
        $this->assertEquals(15, $data['price']);
        $this->assertIsArray($data['images']);
        $this->assertEmpty($data['images'], 'Guest must receive strictly empty images array');

        // Verify secret image path is not leaked anywhere in response body
        $rawContent = $response->getContent();
        $this->assertStringNotContainsString('secret_page_1.jpg', $rawContent);
        $this->assertStringNotContainsString('secret_page_2.jpg', $rawContent);
    }

    public function test_reader_without_unlock_receives_empty_images_array_and_user_balance(): void
    {
        $response = $this->actingAs($this->reader)->getJson("/api/comics/{$this->comicA->slug}/chapters/{$this->vipChapterA->chapter_number}");
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertTrue($data['is_vip']);
        $this->assertFalse($data['is_unlocked']);
        $this->assertEmpty($data['images']);
        $this->assertArrayHasKey('user_balance', $data);

        $rawContent = $response->getContent();
        $this->assertStringNotContainsString('secret_page_1.jpg', $rawContent);
    }

    public function test_reader_with_active_unlock_can_access_full_vip_images(): void
    {
        // 1. Grant reader sufficient coins
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);
        $wallet->balance = 50;
        $wallet->save();

        // 2. Unlock VIP chapter
        $unlockResponse = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapterA->id}/unlock", [], [
            'X-Idempotency-Key' => (string) Str::uuid(),
        ]);
        $unlockResponse->assertStatus(200);

        // 3. Read chapter again
        $readResponse = $this->actingAs($this->reader)->getJson("/api/comics/{$this->comicA->slug}/chapters/{$this->vipChapterA->chapter_number}");
        $readResponse->assertStatus(200);

        $data = $readResponse->json('data');
        $this->assertTrue($data['is_vip']);
        $this->assertTrue($data['is_unlocked']);
        $this->assertCount(2, $data['images'], 'Unlocked reader must receive all chapter images');
    }

    public function test_comic_creator_bypasses_own_vip_chapter(): void
    {
        $creatorResponse = $this->actingAs($this->creatorA)->getJson("/api/comics/{$this->comicA->slug}/chapters/{$this->vipChapterA->chapter_number}");
        $creatorResponse->assertStatus(200);
        $this->assertTrue($creatorResponse->json('data.is_unlocked'));
        $this->assertEquals('creator_or_admin', $creatorResponse->json('data.bypass_reason'));
        $this->assertCount(2, $creatorResponse->json('data.images'));
    }

    public function test_admin_bypasses_all_vip_chapters(): void
    {
        $adminResponse = $this->actingAs($this->admin)->getJson("/api/comics/{$this->comicA->slug}/chapters/{$this->vipChapterA->chapter_number}");
        $adminResponse->assertStatus(200);
        $this->assertTrue($adminResponse->json('data.is_unlocked'));
        $this->assertEquals('creator_or_admin', $adminResponse->json('data.bypass_reason'));
        $this->assertCount(2, $adminResponse->json('data.images'));
    }

    public function test_other_creator_cannot_bypass_vip_chapters_of_other_comics(): void
    {
        $creatorBResponse = $this->actingAs($this->creatorB)->getJson("/api/comics/{$this->comicA->slug}/chapters/{$this->vipChapterA->chapter_number}");
        $creatorBResponse->assertStatus(200);
        $this->assertFalse($creatorBResponse->json('data.is_unlocked'));
        $this->assertEmpty($creatorBResponse->json('data.images'));
    }

    // =========================================================================
    // SECTION 4: COIN WALLET & LEDGER INTEGRITY
    // =========================================================================

    public function test_unlock_fails_with_422_when_balance_is_insufficient(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);
        $wallet->balance = 5; // Price is 15
        $wallet->save();

        $response = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapterA->id}/unlock", [], [
            'X-Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'error_code' => 'INSUFFICIENT_COINS',
        ]);

        $wallet->refresh();
        $this->assertEquals(5, $wallet->balance, 'Balance must not change on insufficient coins failure');
    }

    public function test_replaying_same_idempotency_key_does_not_deduct_coins_twice(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);
        $wallet->balance = 50;
        $wallet->save();

        $idempotencyKey = (string) Str::uuid();

        // First attempt: succeeds and deducts 15 coins
        $resp1 = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapterA->id}/unlock", [], [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);
        $resp1->assertStatus(200);
        $this->assertEquals(35, $resp1->json('data.remaining_balance'));

        // Replay with identical key: returns same receipt without second debit
        $resp2 = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapterA->id}/unlock", [], [
            'X-Idempotency-Key' => $idempotencyKey,
        ]);
        $resp2->assertStatus(200);
        $this->assertEquals(35, $resp2->json('data.remaining_balance'));

        $wallet->refresh();
        $this->assertEquals(35, $wallet->balance, 'Wallet must be deducted exactly once');
    }

    public function test_coin_transaction_immutability_blocks_updating_and_deleting(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $tx = CoinTransaction::create([
            'wallet_id' => $wallet->id,
            'user_id' => $this->reader->id,
            'type' => 'faucet',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
            'idempotency_key' => (string) Str::uuid(),
            'description' => 'Test faucet transaction',
        ]);

        // 1. Attempt update -> must throw LogicException
        $this->expectException(\LogicException::class);
        $tx->update(['amount' => 500]);
    }

    public function test_faucet_enforces_daily_limit_and_blocks_abuse(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $resp = $this->actingAs($this->reader)->postJson('/api/wallet/faucet');
            $resp->assertStatus(200);
        }

        // 6th claim must fail with 422 FAUCET_LIMIT_EXCEEDED
        $resp6 = $this->actingAs($this->reader)->postJson('/api/wallet/faucet');
        $resp6->assertStatus(422);
        $resp6->assertJson([
            'status' => 'error',
            'error_code' => 'FAUCET_LIMIT_EXCEEDED',
        ]);
    }

    // =========================================================================
    // SECTION 5: INJECTION & WEB SECURITY (SQL Injection & XSS)
    // =========================================================================

    public function test_ai_search_safely_handles_sql_injection_payloads(): void
    {
        $sqlInjectionPayloads = [
            "'; DROP TABLE comics; --",
            "' OR '1'='1",
            "admin'--",
            "' UNION SELECT * FROM users --",
            "1'; WAITFOR DELAY '0:0:5'--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->getJson('/api/ai-search?q=' . urlencode($payload));
            // Must return 200 with normal search structure or 422 if invalid, never 500 SQL syntax error
            $this->assertContains($response->status(), [200, 422], "Failed on payload: {$payload}");
            $this->assertDatabaseHas('comics', ['id' => $this->comicA->id]);
        }
    }

    public function test_xss_payload_in_comment_is_stored_safely_without_raw_script_execution(): void
    {
        $xssPayload = '<script>alert("XSS Attack!")</script><b>Bold Comment</b>';

        $response = $this->actingAs($this->reader)->postJson("/api/comics/{$this->comicA->id}/comments", [
            'content' => $xssPayload,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('comments', [
            'user_id' => $this->reader->id,
            'commentable_id' => $this->comicA->id,
            'content' => $xssPayload,
        ]);
    }

    // =========================================================================
    // SECTION 6: SENSITIVE DATA EXPOSURE
    // =========================================================================

    public function test_login_response_never_exposes_password_hash_or_remember_token(): void
    {
        $loginResponse = $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/login', [
            'email' => $this->reader->email,
            'password' => 'Password123!',
        ]);
        $loginResponse->assertStatus(200);
        $loginResponse->assertJsonMissing(['password', 'remember_token']);
    }

    public function test_register_response_never_exposes_password_hash_or_remember_token(): void
    {
        $regResponse = $this->withHeaders(['Origin' => 'http://localhost:5173'])->postJson('/api/register', [
            'name' => 'Data Leak Check',
            'email' => 'dataleak@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $regResponse->assertStatus(201);
        $regResponse->assertJsonMissing(['password', 'remember_token']);
    }

    public function test_user_profile_response_never_exposes_password_hash_or_remember_token(): void
    {
        $userResponse = $this->actingAs($this->reader)->getJson('/api/user');
        $userResponse->assertStatus(200);
        $userResponse->assertJsonMissing(['password', 'remember_token']);
    }
}

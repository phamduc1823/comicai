<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterImage;
use App\Models\ChapterUnlock;
use App\Models\CoinTransaction;
use App\Models\Comic;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\ChapterUnlockServiceInterface;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VipChapterUnlockTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $creator;
    private User $otherCreator;
    private User $admin;
    private Comic $comic;
    private Chapter $vipChapter;
    private Chapter $freeChapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = User::create([
            'name' => 'Độc Giả Demo',
            'email' => 'reader@example.com',
            'password' => 'secret123',
        ]);

        $this->creator = User::create([
            'name' => 'Tác Giả Chính',
            'email' => 'creator@example.com',
            'password' => 'secret123',
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();

        $this->otherCreator = User::create([
            'name' => 'Tác Giả Khác',
            'email' => 'othercreator@example.com',
            'password' => 'secret123',
        ]);
        $this->otherCreator->role = 'creator';
        $this->otherCreator->save();

        $this->admin = User::create([
            'name' => 'Quản Trị Viên',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->comic = Comic::create([
            'title' => 'Võ Thần Đại Lục',
            'slug' => 'vo-than-dai-luc',
            'author_name' => 'Tác Giả Chính',
            'user_id' => $this->creator->id,
            'cover_path' => 'placeholders/comics/demo-cover.webp',
            'status' => 'ongoing',
        ]);
        $this->comic->approval_status = 'approved';
        $this->comic->approved_by = $this->admin->id;
        $this->comic->approved_at = now();
        $this->comic->save();

        $this->freeChapter = Chapter::create([
            'comic_id' => $this->comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1 Miễn Phí',
            'slug' => 'chuong-1',
            'status' => 'published',
            'is_vip' => false,
            'price' => 0,
        ]);
        ChapterImage::create([
            'chapter_id' => $this->freeChapter->id,
            'page_number' => 1,
            'image_path' => 'chapters/1/page_1.webp',
        ]);

        $this->vipChapter = Chapter::create([
            'comic_id' => $this->comic->id,
            'chapter_number' => '2.00',
            'title' => 'Chương 2 VIP',
            'slug' => 'chuong-2',
            'status' => 'published',
            'is_vip' => true,
            'price' => 15,
        ]);
        ChapterImage::create([
            'chapter_id' => $this->vipChapter->id,
            'page_number' => 1,
            'image_path' => 'chapters/2/page_1.webp',
        ]);
        ChapterImage::create([
            'chapter_id' => $this->vipChapter->id,
            'page_number' => 2,
            'image_path' => 'chapters/2/page_2.webp',
        ]);
    }

    public function test_creator_can_create_chapter_with_is_vip_and_custom_price(): void
    {
        $response = $this->actingAs($this->creator)->postJson("/api/creator/comics/{$this->comic->id}/chapters", [
            'chapter_number' => '3',
            'title' => 'Chương 3 VIP Mới',
            'is_vip' => true,
            'price' => 20,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'is_vip' => true,
                    'price' => 20,
                ],
            ]);

        $this->assertDatabaseHas('chapters', [
            'comic_id' => $this->comic->id,
            'chapter_number' => '3.00',
            'is_vip' => true,
            'price' => 20,
        ]);
    }

    public function test_creator_cannot_set_vip_price_outside_allowed_range(): void
    {
        // Lower than 5
        $resLow = $this->actingAs($this->creator)->postJson("/api/creator/comics/{$this->comic->id}/chapters", [
            'chapter_number' => '4',
            'title' => 'Chương 4',
            'is_vip' => true,
            'price' => 4,
        ]);
        $resLow->assertStatus(422);

        // Higher than 50
        $resHigh = $this->actingAs($this->creator)->postJson("/api/creator/comics/{$this->comic->id}/chapters", [
            'chapter_number' => '5',
            'title' => 'Chương 5',
            'is_vip' => true,
            'price' => 51,
        ]);
        $resHigh->assertStatus(422);
    }

    public function test_reader_can_unlock_vip_chapter_with_sufficient_balance(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader); // 100 coins

        $response = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapter->id}/unlock");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Mở khóa chương thành công.',
                'data' => [
                    'chapter_id' => $this->vipChapter->id,
                    'comic_id' => $this->comic->id,
                    'price_paid' => 15,
                    'remaining_balance' => 85,
                    'is_unlocked' => true,
                    'already_unlocked' => false,
                    'is_repurchase' => false,
                ],
            ]);

        $wallet->refresh();
        $this->assertEquals(85, $wallet->balance);

        $this->assertDatabaseHas('chapter_unlocks', [
            'user_id' => $this->reader->id,
            'chapter_id' => $this->vipChapter->id,
            'status' => 'active',
            'price_paid' => 15,
            'unlock_count' => 1,
        ]);

        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $this->reader->id,
            'type' => 'chapter_unlock',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 85,
        ]);
    }

    public function test_unlock_fails_with_422_when_balance_is_insufficient_and_does_not_record_debit(): void
    {
        $wallet = Wallet::create([
            'user_id' => $this->reader->id,
            'balance' => 5, // insufficient for 15 coins
        ]);

        $response = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapter->id}/unlock");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'error_code' => 'INSUFFICIENT_COINS',
            ]);

        $wallet->refresh();
        $this->assertEquals(5, $wallet->balance);

        $this->assertDatabaseMissing('chapter_unlocks', [
            'user_id' => $this->reader->id,
            'chapter_id' => $this->vipChapter->id,
        ]);
        $this->assertDatabaseMissing('coin_transactions', [
            'user_id' => $this->reader->id,
            'type' => 'chapter_unlock',
        ]);
    }

    public function test_unlock_free_chapter_fails_with_422_not_vip(): void
    {
        app(WalletServiceInterface::class)->getOrCreateWallet($this->reader);

        $response = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->freeChapter->id}/unlock");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'error_code' => 'CHAPTER_NOT_VIP',
            ]);
    }

    public function test_unlock_unapproved_or_draft_chapter_fails_with_404(): void
    {
        $draftChapter = Chapter::create([
            'comic_id' => $this->comic->id,
            'chapter_number' => '10.00',
            'title' => 'Chương Nháp',
            'slug' => 'chuong-10',
            'status' => 'draft',
            'is_vip' => true,
            'price' => 10,
        ]);

        $response = $this->actingAs($this->reader)->postJson("/api/chapters/{$draftChapter->id}/unlock");
        $response->assertStatus(404);
    }

    public function test_replaying_same_idempotency_key_with_same_payload_returns_previous_response_without_deducting_coins(): void
    {
        app(WalletServiceInterface::class)->getOrCreateWallet($this->reader);
        $key = (string) Str::uuid();

        // 1st request
        $res1 = $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock", ['dummy' => 'payload']);
        $res1->assertStatus(200);

        $wallet = Wallet::where('user_id', $this->reader->id)->first();
        $this->assertEquals(85, $wallet->balance);

        // 2nd request with same key and same payload
        $res2 = $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock", ['dummy' => 'payload']);
        $res2->assertStatus(200);

        $wallet->refresh();
        $this->assertEquals(85, $wallet->balance); // balance remains 85, no double charge

        $unlockCount = CoinTransaction::where('user_id', $this->reader->id)
            ->where('type', 'chapter_unlock')
            ->count();
        $this->assertEquals(1, $unlockCount);
    }

    public function test_replaying_same_idempotency_key_with_different_payload_fails_with_422_mismatch(): void
    {
        app(WalletServiceInterface::class)->getOrCreateWallet($this->reader);
        $key = (string) Str::uuid();

        // 1st request
        $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock", ['dummy' => 'payload1']);

        // 2nd request with same key but different payload
        $res2 = $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock", ['dummy' => 'payload2']);

        $res2->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'error_code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
            ]);
    }

    public function test_unlocking_already_unlocked_chapter_with_new_key_returns_already_unlocked_without_charge(): void
    {
        app(WalletServiceInterface::class)->getOrCreateWallet($this->reader);

        // First unlock with key 1
        $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', 'key-1')
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock");

        // Second unlock with new key 2
        $res2 = $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', 'key-2')
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock");

        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Chương này đã được mở khóa trước đó.',
                'data' => [
                    'price_paid' => 0,
                    'already_unlocked' => true,
                    'is_unlocked' => true,
                ],
            ]);

        $wallet = Wallet::where('user_id', $this->reader->id)->first();
        $this->assertEquals(85, $wallet->balance);
    }

    public function test_duplicate_key_on_chapter_unlocks_resolves_gracefully_to_already_unlocked(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $tx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 85,
            'description' => 'Mở khóa chương VIP',
            'created_at' => now(),
        ]);

        // Pre-create an active unlock
        ChapterUnlock::create([
            'user_id' => $this->reader->id,
            'chapter_id' => $this->vipChapter->id,
            'comic_id' => $this->comic->id,
            'transaction_id' => $tx->id,
            'price_paid' => 15,
            'status' => 'active',
            'unlock_count' => 1,
            'unlocked_at' => now(),
        ]);

        $unlockService = app(ChapterUnlockServiceInterface::class);
        $result = $unlockService->unlockChapter($this->reader, $this->vipChapter, 'new-key');

        $this->assertTrue($result['data']['already_unlocked']);
        $this->assertEquals(0, $result['data']['price_paid']);
    }

    public function test_duplicate_key_on_coin_transactions_resolves_via_idempotency_rules(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $key = 'concurrent-key';
        $payload = ['foo' => 'bar'];
        $cleanPayload = $payload;
        ksort($cleanPayload);
        $hash = hash('sha256', json_encode($cleanPayload));

        CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 85,
            'idempotency_key' => $key,
            'payload_hash' => $hash,
            'description' => 'Mở khóa chương VIP',
            'reference_type' => Chapter::class,
            'reference_id' => $this->vipChapter->id,
            'created_at' => now(),
        ]);

        $unlockService = app(ChapterUnlockServiceInterface::class);
        $result = $unlockService->unlockChapter($this->reader, $this->vipChapter, $key, $payload);

        $this->assertEquals(15, $result['data']['price_paid']);
        $this->assertTrue($result['data']['is_unlocked']);
    }

    public function test_unrecognized_duplicate_key_constraint_rethrows_and_does_not_swallow(): void
    {
        // Verify unrecognized query exception is rethrown
        $this->expectException(QueryException::class);

        // Trigger a unique constraint on users email to simulate unexpected DB exception
        User::create([
            'name' => 'Duplicate Email',
            'email' => 'reader@example.com',
            'password' => 'secret123',
        ]);
    }

    public function test_guest_cannot_access_vip_chapter_images_and_receives_empty_array(): void
    {
        $response = $this->getJson("/api/comics/{$this->comic->slug}/chapters/2.00");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => true,
                    'is_unlocked' => false,
                    'price' => 15,
                    'images' => [],
                ],
            ]);
    }

    public function test_reader_without_unlock_receives_empty_images_and_pricing_metadata(): void
    {
        app(WalletServiceInterface::class)->getOrCreateWallet($this->reader);

        $response = $this->actingAs($this->reader)->getJson("/api/comics/{$this->comic->slug}/chapters/2.00");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => true,
                    'is_unlocked' => false,
                    'price' => 15,
                    'user_balance' => 100,
                    'images' => [],
                ],
            ]);
    }

    public function test_reader_with_active_unlock_receives_full_images(): void
    {
        app(WalletServiceInterface::class)->getOrCreateWallet($this->reader);

        // Unlock
        $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapter->id}/unlock");

        $response = $this->actingAs($this->reader)->getJson("/api/comics/{$this->comic->slug}/chapters/2.00");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => true,
                    'is_unlocked' => true,
                ],
            ]);

        $images = $response->json('data.images');
        $this->assertCount(2, $images);
    }

    public function test_creator_bypasses_vip_and_reads_own_comic_chapters_freely(): void
    {
        $response = $this->actingAs($this->creator)->getJson("/api/comics/{$this->comic->slug}/chapters/2.00");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => true,
                    'is_unlocked' => true,
                    'bypass_reason' => 'creator_or_admin',
                ],
            ]);

        $images = $response->json('data.images');
        $this->assertCount(2, $images);
    }

    public function test_creator_cannot_bypass_vip_chapters_of_other_creators_comics(): void
    {
        $response = $this->actingAs($this->otherCreator)->getJson("/api/comics/{$this->comic->slug}/chapters/2.00");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => true,
                    'is_unlocked' => false,
                    'images' => [],
                ],
            ]);
    }

    public function test_admin_bypasses_all_vip_chapters_across_the_system(): void
    {
        $response = $this->actingAs($this->admin)->getJson("/api/comics/{$this->comic->slug}/chapters/2.00");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => true,
                    'is_unlocked' => true,
                    'bypass_reason' => 'creator_or_admin',
                ],
            ]);

        $images = $response->json('data.images');
        $this->assertCount(2, $images);
    }

    public function test_free_chapters_remain_publicly_readable_by_all_guests_and_readers(): void
    {
        // Guest
        $resGuest = $this->getJson("/api/comics/{$this->comic->slug}/chapters/1.00");
        $resGuest->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => false,
                    'is_unlocked' => true,
                ],
            ]);
        $this->assertCount(1, $resGuest->json('data.images'));

        // Reader
        $resReader = $this->actingAs($this->reader)->getJson("/api/comics/{$this->comic->slug}/chapters/1.00");
        $resReader->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => false,
                    'is_unlocked' => true,
                ],
            ]);
        $this->assertCount(1, $resReader->json('data.images'));
    }

    public function test_unlock_with_revoked_status_denies_reader_images_access(): void
    {
        $wallet = app(WalletServiceInterface::class)->getOrCreateWallet($this->reader);
        $tx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 85,
            'description' => 'Mở khóa chương VIP',
            'created_at' => now(),
        ]);

        ChapterUnlock::create([
            'user_id' => $this->reader->id,
            'chapter_id' => $this->vipChapter->id,
            'comic_id' => $this->comic->id,
            'transaction_id' => $tx->id,
            'price_paid' => 15,
            'status' => 'revoked',
            'revoked_at' => now(),
            'unlock_count' => 1,
            'unlocked_at' => now(),
        ]);

        $response = $this->actingAs($this->reader)->getJson("/api/comics/{$this->comic->slug}/chapters/2.00");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'is_vip' => true,
                    'is_unlocked' => false,
                    'images' => [],
                ],
            ]);
    }

    public function test_user_can_repurchase_chapter_after_refund_reactivating_unlock_and_recording_new_debit(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader); // 100 coins

        $tx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 85,
            'description' => 'Mở khóa chương VIP',
            'created_at' => now(),
        ]);

        // Pre-create a revoked unlock
        $unlock = ChapterUnlock::create([
            'user_id' => $this->reader->id,
            'chapter_id' => $this->vipChapter->id,
            'comic_id' => $this->comic->id,
            'transaction_id' => $tx->id,
            'price_paid' => 15,
            'status' => 'revoked',
            'revoked_at' => now()->subHour(),
            'unlock_count' => 1,
            'unlocked_at' => now()->subHour(),
        ]);

        // Reader repurchases
        $response = $this->actingAs($this->reader)->postJson("/api/chapters/{$this->vipChapter->id}/unlock");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Mua lại chương VIP thành công sau khi hoàn tiền.',
                'data' => [
                    'chapter_id' => $this->vipChapter->id,
                    'comic_id' => $this->comic->id,
                    'price_paid' => 15,
                    'remaining_balance' => 85,
                    'is_unlocked' => true,
                    'already_unlocked' => false,
                    'is_repurchase' => true,
                ],
            ]);

        // Unlock record must be reactivated
        $unlock->refresh();
        $this->assertEquals('active', $unlock->status);
        $this->assertNull($unlock->revoked_at);
        $this->assertEquals(2, $unlock->unlock_count);

        // Wallet balance debited
        $wallet->refresh();
        $this->assertEquals(85, $wallet->balance);
    }

    public function test_retrying_original_purchase_request_after_refund_returns_original_receipt_and_does_not_reactivate_unlock(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $key = 'original-purchase-key';

        // Unlock with idempotency key
        $unlockRes = $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock");
        $unlockRes->assertStatus(200);

        $unlockTx = CoinTransaction::where('user_id', $this->reader->id)
            ->where('idempotency_key', $key)
            ->first();
        $this->assertNotNull($unlockTx);

        // Admin refunds (flush reader request header so admin refund does not inherit it)
        $this->flushHeaders();
        $refundRes = $this->actingAs($this->admin)->postJson('/api/admin/vip/refund', [
            'transaction_id' => $unlockTx->id,
            'reason' => 'Hoàn tiền',
        ]);
        $refundRes->assertStatus(200);

        $unlock = ChapterUnlock::where('user_id', $this->reader->id)
            ->where('chapter_id', $this->vipChapter->id)
            ->first();
        $this->assertNotNull($unlock);
        $this->assertEquals('revoked', $unlock->status);

        // Reader retries the original request with the same idempotency key
        $retryResponse = $this->actingAs($this->reader)
            ->withHeader('X-Idempotency-Key', $key)
            ->postJson("/api/chapters/{$this->vipChapter->id}/unlock");

        $retryResponse->assertStatus(200);

        // The unlock must REMAIN revoked, NOT reactivated!
        $unlock->refresh();
        $this->assertEquals('revoked', $unlock->status);
    }
}

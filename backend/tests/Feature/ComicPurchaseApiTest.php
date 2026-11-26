<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterUnlock;
use App\Models\CoinTransaction;
use App\Models\Comic;
use App\Models\ComicPurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\ComicPurchaseServiceInterface;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComicPurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected WalletServiceInterface $walletService;
    protected ComicPurchaseServiceInterface $comicPurchaseService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walletService = app(WalletServiceInterface::class);
        $this->comicPurchaseService = app(ComicPurchaseServiceInterface::class);
    }

    protected function createComic(array $attributes = []): Comic
    {
        $creatorId = $attributes['user_id'] ?? User::factory()->create(['role' => 'creator'])->id;
        $title = $attributes['title'] ?? 'Truyện Test VIP ' . Str::random(5);

        $comic = new Comic(array_merge([
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::random(5),
            'author_name' => 'Tác Giả VIP',
            'user_id' => $creatorId,
            'cover_path' => 'placeholders/comics/demo.webp',
            'status' => 'ongoing',
            'is_vip' => true,
            'price' => 50,
        ], $attributes));

        $comic->approval_status = $attributes['approval_status'] ?? 'approved';
        $comic->save();

        return $comic;
    }

    protected function createChapter(Comic $comic, array $attributes = []): Chapter
    {
        $num = $attributes['chapter_number'] ?? '1.0';

        return Chapter::create(array_merge([
            'comic_id' => $comic->id,
            'chapter_number' => $num,
            'title' => "Chương $num",
            'slug' => "chuong-" . str_replace('.', '-', (string) $num),
            'status' => 'published',
            'is_vip' => $comic->is_vip,
            'price' => $comic->price,
        ], $attributes));
    }

    protected function createWallet(User $user, int $balance): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'balance' => $balance,
        ]);
    }

    public function test_user_can_purchase_vip_comic_with_sufficient_balance(): void
    {
        $creator = User::factory()->create(['role' => 'creator']);
        $comic = $this->createComic([
            'user_id' => $creator->id,
            'is_vip' => true,
            'price' => 50,
        ]);

        $reader = User::factory()->create(['role' => 'reader']);
        $this->createWallet($reader, 100);

        $response = $this->actingAs($reader)->postJson("/api/comics/{$comic->id}/purchase");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_purchased', true)
            ->assertJsonPath('data.price_paid', 50)
            ->assertJsonPath('data.remaining_balance', 50);

        $this->assertDatabaseHas('comic_purchases', [
            'user_id' => $reader->id,
            'comic_id' => $comic->id,
            'status' => 'active',
            'price_paid' => 50,
        ]);

        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $reader->id,
            'type' => 'comic_purchase',
            'amount' => 50,
            'balance_after' => 50,
        ]);
    }

    public function test_purchase_fails_when_balance_is_insufficient(): void
    {
        $comic = $this->createComic([
            'is_vip' => true,
            'price' => 50,
        ]);

        $reader = User::factory()->create(['role' => 'reader']);
        $this->createWallet($reader, 20);

        $response = $this->actingAs($reader)->postJson("/api/comics/{$comic->id}/purchase");

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'INSUFFICIENT_COINS');

        $this->assertDatabaseMissing('comic_purchases', [
            'user_id' => $reader->id,
            'comic_id' => $comic->id,
        ]);

        $this->assertEquals(20, $this->walletService->getBalance($reader));
    }

    public function test_purchasing_already_purchased_comic_does_not_deduct_coins_again(): void
    {
        $comic = $this->createComic([
            'is_vip' => true,
            'price' => 40,
        ]);

        $reader = User::factory()->create(['role' => 'reader']);
        $this->createWallet($reader, 100);

        // First purchase
        $this->actingAs($reader)->postJson("/api/comics/{$comic->id}/purchase")->assertStatus(200);
        $this->assertEquals(60, $this->walletService->getBalance($reader));

        // Second purchase attempt
        $res = $this->actingAs($reader)->postJson("/api/comics/{$comic->id}/purchase");
        $res->assertStatus(200)
            ->assertJsonPath('data.already_purchased', true)
            ->assertJsonPath('data.price_paid', 0);

        // Balance remains 60
        $this->assertEquals(60, $this->walletService->getBalance($reader));
    }

    public function test_user_who_purchased_comic_can_read_all_chapters_including_future_ones(): void
    {
        $creator = User::factory()->create(['role' => 'creator']);
        $comic = $this->createComic([
            'user_id' => $creator->id,
            'is_vip' => true,
            'price' => 30,
        ]);

        // Chapter 1 exists before purchase
        $chapter1 = $this->createChapter($comic, [
            'chapter_number' => '1.0',
            'status' => 'published',
            'is_vip' => true,
        ]);

        $reader = User::factory()->create(['role' => 'reader']);
        $this->createWallet($reader, 100);

        // Before purchase: reader cannot read chapter 1
        $this->assertFalse($this->comicPurchaseService->canAccessChapter($reader, $chapter1));

        // Reader purchases comic
        $this->actingAs($reader)->postJson("/api/comics/{$comic->id}/purchase")->assertStatus(200);

        // Now reader can read chapter 1
        $this->assertTrue($this->comicPurchaseService->canAccessChapter($reader, $chapter1));

        // Creator publishes Chapter 2 in the future
        $chapter2 = $this->createChapter($comic, [
            'chapter_number' => '2.0',
            'status' => 'published',
            'is_vip' => true,
        ]);

        // Reader automatically has access to Chapter 2 without paying again!
        $this->assertTrue($this->comicPurchaseService->canAccessChapter($reader, $chapter2));

        // Via API reader view
        $response = $this->actingAs($reader)->getJson("/api/comics/{$comic->slug}/chapters/2");
        $response->assertStatus(200)
            ->assertJsonPath('data.is_unlocked', true);
    }

    public function test_guest_and_unpurchased_reader_cannot_read_vip_comic_chapters(): void
    {
        $comic = $this->createComic([
            'is_vip' => true,
            'price' => 30,
        ]);

        $chapter = $this->createChapter($comic, [
            'chapter_number' => '1.0',
            'status' => 'published',
        ]);

        // Guest check
        $this->assertFalse($this->comicPurchaseService->canAccessChapter(null, $chapter));
        $resGuest = $this->getJson("/api/comics/{$comic->slug}/chapters/1");
        $resGuest->assertStatus(200)
            ->assertJsonPath('data.is_unlocked', false)
            ->assertJsonPath('data.images', []);

        // Unpurchased reader check
        $reader = User::factory()->create();
        $this->assertFalse($this->comicPurchaseService->canAccessChapter($reader, $chapter));
        $resReader = $this->actingAs($reader)->getJson("/api/comics/{$comic->slug}/chapters/1");
        $resReader->assertStatus(200)
            ->assertJsonPath('data.is_unlocked', false)
            ->assertJsonPath('data.images', []);
    }

    public function test_creator_and_admin_bypass_vip_comic_access(): void
    {
        $creator = User::factory()->create(['role' => 'creator']);
        $comic = $this->createComic([
            'user_id' => $creator->id,
            'is_vip' => true,
            'price' => 30,
        ]);

        $chapter = $this->createChapter($comic, [
            'chapter_number' => '1.0',
            'status' => 'published',
        ]);

        // Creator of the comic can read freely
        $this->assertTrue($this->comicPurchaseService->canAccessChapter($creator, $chapter));

        // Another creator cannot bypass
        $otherCreator = User::factory()->create(['role' => 'creator']);
        $this->assertFalse($this->comicPurchaseService->canAccessChapter($otherCreator, $chapter));

        // Admin can read freely
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($this->comicPurchaseService->canAccessChapter($admin, $chapter));
    }

    public function test_legacy_chapter_unlock_keeps_backward_compatibility(): void
    {
        $comic = $this->createComic([
            'is_vip' => true,
            'price' => 50,
        ]);

        $chapter1 = $this->createChapter($comic, [
            'chapter_number' => '1.0',
            'status' => 'published',
            'is_vip' => true,
        ]);

        $chapter2 = $this->createChapter($comic, [
            'chapter_number' => '2.0',
            'status' => 'published',
            'is_vip' => true,
        ]);

        $reader = User::factory()->create();
        $wallet = $this->createWallet($reader, 100);

        $tx = CoinTransaction::create([
            'transaction_code' => 'CTX-LEGACY-' . Str::upper(Str::random(8)),
            'user_id' => $reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 10,
            'balance_before' => 100,
            'balance_after' => 90,
            'idempotency_key' => Str::uuid()->toString(),
            'description' => 'Legacy chapter unlock',
        ]);

        // Create legacy chapter unlock record for chapter 1 only
        ChapterUnlock::create([
            'user_id' => $reader->id,
            'chapter_id' => $chapter1->id,
            'comic_id' => $comic->id,
            'transaction_id' => $tx->id,
            'status' => 'active',
            'price_paid' => 10,
            'unlock_count' => 1,
            'unlocked_at' => now(),
        ]);

        // Reader can read chapter 1 via legacy unlock
        $this->assertTrue($this->comicPurchaseService->canAccessChapter($reader, $chapter1));

        // Reader cannot read chapter 2 since they didn't purchase the full comic
        $this->assertFalse($this->comicPurchaseService->canAccessChapter($reader, $chapter2));
    }

    public function test_individual_chapter_unlock_api_is_deprecated_and_rejects_with_422(): void
    {
        $comic = $this->createComic([
            'is_vip' => true,
            'price' => 50,
        ]);

        $chapter = $this->createChapter($comic, [
            'chapter_number' => '1.0',
            'status' => 'published',
            'is_vip' => true,
        ]);

        $reader = User::factory()->create();
        $this->createWallet($reader, 100);

        $res = $this->actingAs($reader)->postJson("/api/chapters/{$chapter->id}/unlock");

        $res->assertStatus(422)
            ->assertJsonPath('error_code', 'CHAPTER_UNLOCK_DEPRECATED');

        // Coins are NOT deducted
        $this->assertEquals(100, $this->walletService->getBalance($reader));
    }

    public function test_idempotency_key_prevents_duplicate_charge_on_comic_purchase(): void
    {
        $comic = $this->createComic([
            'is_vip' => true,
            'price' => 35,
        ]);

        $reader = User::factory()->create();
        $this->createWallet($reader, 100);

        $idempotencyKey = 'comic-idempotent-key-12345';

        $res1 = $this->actingAs($reader)->postJson(
            "/api/comics/{$comic->id}/purchase",
            [],
            ['X-Idempotency-Key' => $idempotencyKey]
        );
        $res1->assertStatus(200);

        $this->assertEquals(65, $this->walletService->getBalance($reader));

        // Replaying same key returns previous response without charging again
        $res2 = $this->actingAs($reader)->postJson(
            "/api/comics/{$comic->id}/purchase",
            [],
            ['X-Idempotency-Key' => $idempotencyKey]
        );
        $res2->assertStatus(200)
            ->assertJsonPath('data.price_paid', 35)
            ->assertJsonPath('data.remaining_balance', 65);

        $this->assertEquals(65, $this->walletService->getBalance($reader));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Chapter;
use App\Models\ChapterUnlock;
use App\Models\CoinTransaction;
use App\Models\Comic;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletAndLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private User $admin;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = User::create([
            'name' => 'Độc Giả Demo',
            'email' => 'reader@example.com',
            'password' => 'secret123',
        ]);

        $this->admin = User::create([
            'name' => 'Quản Trị Viên',
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);
        $this->admin->role = 'admin';
        $this->admin->save();

        $this->creator = User::create([
            'name' => 'Tác Giả Demo',
            'email' => 'creator@example.com',
            'password' => 'secret123',
        ]);
        $this->creator->role = 'creator';
        $this->creator->save();
    }

    public function test_user_registration_automatically_creates_wallet_with_welcome_bonus_100_coins(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Người Dùng Mới',
            'email' => 'newuser@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(100, $wallet->balance);

        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'welcome_bonus',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);
    }

    public function test_welcome_bonus_is_granted_strictly_once_even_on_registration_retry(): void
    {
        $walletService = app(WalletServiceInterface::class);

        $firstTx = $walletService->grantWelcomeBonus($this->reader);
        $this->assertNotNull($firstTx);
        $this->assertEquals(100, $firstTx->amount);

        // Subsequent call must not create another transaction or increment balance
        $walletService->grantWelcomeBonus($this->reader);

        $wallet = Wallet::where('user_id', $this->reader->id)->first();
        $this->assertEquals(100, $wallet->balance);

        $welcomeCount = CoinTransaction::where('user_id', $this->reader->id)
            ->where('type', 'welcome_bonus')
            ->count();
        $this->assertEquals(1, $welcomeCount);
    }

    public function test_existing_users_without_wallet_are_lazily_initialized_with_default_wallet(): void
    {
        $this->assertDatabaseMissing('wallets', ['user_id' => $this->reader->id]);

        $response = $this->actingAs($this->reader)->getJson('/api/wallet');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'user_id' => $this->reader->id,
                    'balance' => 100,
                    'currency' => 'COIN',
                    'faucet_available_today' => 5,
                ],
            ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $this->reader->id,
            'balance' => 100,
        ]);
    }

    public function test_faucet_credits_exactly_100_coins_when_enabled(): void
    {
        $response = $this->actingAs($this->reader)->postJson('/api/wallet/faucet');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Nhận 100 Coin thử nghiệm thành công!',
                'data' => [
                    'credited_amount' => 100,
                    'new_balance' => 200, // 100 welcome + 100 faucet
                    'faucet_remaining_today' => 4,
                ],
            ]);

        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $this->reader->id,
            'type' => 'faucet',
            'amount' => 100,
            'balance_before' => 100,
            'balance_after' => 200,
        ]);
    }

    public function test_faucet_denied_when_feature_flag_is_disabled(): void
    {
        config(['vip.faucet_enabled' => false]);

        $response = $this->actingAs($this->reader)->postJson('/api/wallet/faucet');

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'error_code' => 'FAUCET_DISABLED',
            ]);
    }

    public function test_faucet_enforces_daily_limit_of_five_times_per_day(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->actingAs($this->reader)->postJson('/api/wallet/faucet');
            $res->assertStatus(200);
        }

        // 6th claim must fail with 422
        $response = $this->actingAs($this->reader)->postJson('/api/wallet/faucet');
        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'error_code' => 'FAUCET_LIMIT_EXCEEDED',
            ]);
    }

    public function test_faucet_daily_limit_resets_at_midnight_in_configured_timezone(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        // Seed 5 claims with yesterday timestamp
        for ($i = 0; $i < 5; $i++) {
            CoinTransaction::create([
                'user_id' => $this->reader->id,
                'wallet_id' => $wallet->id,
                'type' => 'faucet',
                'amount' => 100,
                'balance_before' => 100 + ($i * 100),
                'balance_after' => 200 + ($i * 100),
                'description' => 'Nhận Coin thử nghiệm từ faucet',
                'created_at' => now()->subDay(),
            ]);
        }

        // Today's faucet should still have 5 available
        $remaining = $walletService->getRemainingFaucetCount($this->reader);
        $this->assertEquals(5, $remaining);

        $response = $this->actingAs($this->reader)->postJson('/api/wallet/faucet');
        $response->assertStatus(200);
    }

    public function test_faucet_strictly_ignores_client_provided_amount(): void
    {
        $response = $this->actingAs($this->reader)->postJson('/api/wallet/faucet', [
            'amount' => 999999,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'credited_amount' => 100,
                ],
            ]);

        $wallet = Wallet::where('user_id', $this->reader->id)->first();
        $this->assertEquals(200, $wallet->balance); // 100 welcome + 100 faucet, NOT 999999
    }

    public function test_wallet_transactions_endpoint_returns_paginated_history(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $walletService->getOrCreateWallet($this->reader);
        $walletService->claimFaucet($this->reader);

        $response = $this->actingAs($this->reader)->getJson('/api/wallet/transactions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'user_id',
                            'wallet_id',
                            'type',
                            'amount',
                            'balance_before',
                            'balance_after',
                            'created_at',
                        ],
                    ],
                    'current_page',
                    'total',
                ],
            ]);
    }

    public function test_wallet_transactions_can_be_filtered_by_type(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $walletService->getOrCreateWallet($this->reader);
        $walletService->claimFaucet($this->reader);

        $response = $this->actingAs($this->reader)->getJson('/api/wallet/transactions?type=faucet');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('faucet', $data[0]['type']);
    }

    public function test_admin_can_refund_chapter_unlock_transaction_successfully(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $comic = Comic::create([
            'title' => 'Truyện Test',
            'slug' => 'truyen-test-refund',
            'author_name' => 'Tác Giả',
            'user_id' => $this->creator->id,
            'cover_path' => 'placeholders/comics/demo-cover.webp',
            'approval_status' => 'approved',
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'status' => 'ongoing',
        ]);

        $chapter = Chapter::create([
            'comic_id' => $comic->id,
            'chapter_number' => '1.00',
            'title' => 'Chương 1 VIP',
            'slug' => 'chuong-1',
            'status' => 'published',
            'is_vip' => true,
            'price' => 15,
        ]);

        // Reader unlocks chapter
        $unlockTx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 85,
            'description' => 'Mở khóa chương VIP 1.00',
            'reference_type' => Chapter::class,
            'reference_id' => $chapter->id,
            'created_at' => now(),
        ]);
        $wallet->balance = 85;
        $wallet->save();

        $unlock = ChapterUnlock::create([
            'user_id' => $this->reader->id,
            'chapter_id' => $chapter->id,
            'comic_id' => $comic->id,
            'transaction_id' => $unlockTx->id,
            'price_paid' => 15,
            'status' => 'active',
            'unlock_count' => 1,
            'unlocked_at' => now(),
        ]);

        // Admin refunds
        $response = $this->actingAs($this->admin)->postJson('/api/admin/vip/refund', [
            'transaction_id' => $unlockTx->id,
            'reason' => 'Lỗi ảnh chương',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'original_transaction_id' => $unlockTx->id,
                    'refunded_amount' => 15,
                    'user_id' => $this->reader->id,
                    'chapter_id' => $chapter->id,
                    'unlock_status' => 'revoked',
                ],
            ]);

        // Verify wallet refunded
        $wallet->refresh();
        $this->assertEquals(100, $wallet->balance);

        // Verify unlock status is revoked with revoked_at timestamp
        $unlock->refresh();
        $this->assertEquals('revoked', $unlock->status);
        $this->assertNotNull($unlock->revoked_at);
        $this->assertNotNull($unlock->refund_transaction_id);

        // Verify ledger recorded refund
        $this->assertDatabaseHas('coin_transactions', [
            'user_id' => $this->reader->id,
            'type' => 'refund',
            'amount' => 15,
            'balance_before' => 85,
            'balance_after' => 100,
            'reference_type' => CoinTransaction::class,
            'reference_id' => $unlockTx->id,
        ]);
    }

    public function test_non_admin_cannot_access_refund_endpoint(): void
    {
        $response = $this->actingAs($this->reader)->postJson('/api/admin/vip/refund', [
            'transaction_id' => 1,
            'reason' => 'Hack coin',
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_refund_same_transaction_exceeding_original_amount(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $unlockTx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 10,
            'balance_before' => 100,
            'balance_after' => 90,
            'description' => 'Mở khóa chương',
            'created_at' => now(),
        ]);

        // First refund succeeds
        $res1 = $this->actingAs($this->admin)->postJson('/api/admin/vip/refund', [
            'transaction_id' => $unlockTx->id,
            'reason' => 'Lỗi ảnh',
        ]);
        $res1->assertStatus(200);

        // Second refund fails with 422
        $res2 = $this->actingAs($this->admin)->postJson('/api/admin/vip/refund', [
            'transaction_id' => $unlockTx->id,
            'reason' => 'Hoàn tiếp',
        ]);
        $res2->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'error_code' => 'REFUND_EXCEEDS_ORIGINAL_AMOUNT',
            ]);
    }

    public function test_cannot_refund_non_unlock_transaction(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $faucetTx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'faucet',
            'amount' => 100,
            'balance_before' => 100,
            'balance_after' => 200,
            'description' => 'Faucet',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/vip/refund', [
            'transaction_id' => $faucetTx->id,
            'reason' => 'Hoàn faucet',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'error_code' => 'REFUND_EXCEEDS_ORIGINAL_AMOUNT',
            ]);
    }

    public function test_wallet_creation_handles_race_condition_duplicate_key_and_rereads_successfully(): void
    {
        $newUser = User::factory()->create();
        $walletService = app(WalletServiceInterface::class);

        // Pre-create wallet simulating concurrent winning transaction
        $preExistingWallet = Wallet::create([
            'user_id' => $newUser->id,
            'balance' => 100,
        ]);

        $thrown = false;
        Wallet::creating(function ($wallet) use (&$thrown, $newUser) {
            if (! $thrown && $wallet->user_id === $newUser->id) {
                $thrown = true;
                throw new QueryException(
                    'sqlite',
                    'insert into "wallets"',
                    [],
                    new \Exception('UNIQUE constraint failed: wallets.user_id (uk_wallets_user_id)')
                );
            }
        });

        $resultWallet = $walletService->getOrCreateWallet($newUser);

        $this->assertEquals($preExistingWallet->id, $resultWallet->id);
        $this->assertEquals(100, $resultWallet->balance);
    }

    public function test_wallet_creation_rethrows_when_duplicate_key_occurs_but_wallet_not_found(): void
    {
        $newUser = User::factory()->create();
        $walletService = app(WalletServiceInterface::class);

        Wallet::creating(function ($wallet) use ($newUser) {
            if ($wallet->user_id === $newUser->id) {
                throw new QueryException(
                    'sqlite',
                    'insert into "wallets"',
                    [],
                    new \Exception('UNIQUE constraint failed: wallets.user_id (uk_wallets_user_id)')
                );
            }
        });

        $this->expectException(QueryException::class);
        $walletService->getOrCreateWallet($newUser);
    }

    public function test_wallet_creation_rethrows_unrelated_query_exceptions(): void
    {
        $newUser = User::factory()->create();
        $walletService = app(WalletServiceInterface::class);

        Wallet::creating(function ($wallet) use ($newUser) {
            if ($wallet->user_id === $newUser->id) {
                throw new QueryException(
                    'sqlite',
                    'insert into "wallets"',
                    [],
                    new \Exception('Connection lost / syntax error')
                );
            }
        });

        $this->expectException(QueryException::class);
        $walletService->getOrCreateWallet($newUser);
    }

    public function test_welcome_bonus_cannot_be_granted_twice_under_race_condition(): void
    {
        $newUser = User::factory()->create();
        $walletService = app(WalletServiceInterface::class);

        // Call twice
        $wallet1 = $walletService->getOrCreateWallet($newUser);
        $wallet2 = $walletService->getOrCreateWallet($newUser);

        $this->assertEquals($wallet1->id, $wallet2->id);

        // Assert only 1 wallet exists
        $this->assertEquals(1, Wallet::where('user_id', $newUser->id)->count());

        // Assert only 1 welcome_bonus transaction exists
        $txCount = CoinTransaction::where('user_id', $newUser->id)
            ->where('type', 'welcome_bonus')
            ->count();
        $this->assertEquals(1, $txCount);

        $this->assertEquals(100, $wallet1->fresh()->balance);
    }

    public function test_coin_transaction_update_is_blocked_with_logic_exception(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $tx = CoinTransaction::where('user_id', $this->reader->id)->first();
        $this->assertNotNull($tx);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Giao dịch sổ cái CoinTransaction là bất biến (append-only) và không thể chỉnh sửa.');

        $tx->amount = 999;
        $tx->save();
    }

    public function test_coin_transaction_delete_is_blocked_with_logic_exception(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $tx = CoinTransaction::where('user_id', $this->reader->id)->first();
        $this->assertNotNull($tx);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Giao dịch sổ cái CoinTransaction là bất biến (append-only) và không thể xóa.');

        $tx->delete();
    }

    public function test_coin_transaction_create_works_normally(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $newTx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'faucet',
            'amount' => 100,
            'balance_before' => 100,
            'balance_after' => 200,
            'description' => 'Test transaction create',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('coin_transactions', [
            'id' => $newTx->id,
            'type' => 'faucet',
            'amount' => 100,
        ]);
    }

    public function test_database_level_unique_constraint_blocks_duplicate_refund_transaction(): void
    {
        $walletService = app(WalletServiceInterface::class);
        $wallet = $walletService->getOrCreateWallet($this->reader);

        $originalTx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'chapter_unlock',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 85,
            'description' => 'Mở khóa chương test',
            'created_at' => now(),
        ]);

        // Create first refund
        $refund1 = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'refund',
            'amount' => 15,
            'balance_before' => 85,
            'balance_after' => 100,
            'reference_type' => CoinTransaction::class,
            'reference_id' => $originalTx->id,
            'refund_for_transaction_id' => $originalTx->id,
            'description' => 'Hoàn tiền lần 1',
            'created_at' => now(),
        ]);
        $this->assertNotNull($refund1);

        // Attempt to create second refund with the exact same refund_for_transaction_id
        $this->expectException(QueryException::class);
        CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $wallet->id,
            'type' => 'refund',
            'amount' => 15,
            'balance_before' => 100,
            'balance_after' => 115,
            'reference_type' => CoinTransaction::class,
            'reference_id' => $originalTx->id,
            'refund_for_transaction_id' => $originalTx->id,
            'description' => 'Hoàn tiền lần 2 trùng lặp',
            'created_at' => now(),
        ]);
    }
}

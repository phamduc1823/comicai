<?php

namespace Tests\Feature;

use App\Models\CoinTransaction;
use App\Models\Comic;
use App\Models\ComicPurchase;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Contracts\WalletServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWalletPurchasesApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $reader;
    protected Comic $comic;
    protected Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->reader = User::factory()->create(['role' => 'reader']);

        $this->wallet = Wallet::create([
            'user_id' => $this->reader->id,
            'balance' => 500,
            'currency' => 'COIN',
            'faucet_available_today' => 3,
            'faucet_last_claimed_at' => null,
            'welcome_bonus_claimed' => true,
        ]);

        $this->comic = Comic::create([
            'title' => 'VIP Siêu Phẩm',
            'slug' => 'vip-sieu-pham',
            'author_name' => 'Tác giả',
            'user_id' => $this->admin->id,
            'cover_path' => 'covers/test.webp',
            'status' => 'ongoing',
            'approval_status' => 'approved',
            'is_vip' => true,
            'price' => 100,
        ]);
    }

    public function test_admin_can_list_wallets_and_transactions(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/wallets')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/coin-transactions')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_admin_can_list_comic_purchases_and_refund(): void
    {
        // Create comic purchase transaction
        $tx = CoinTransaction::create([
            'user_id' => $this->reader->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'comic_purchase',
            'amount' => 100,
            'balance_before' => 500,
            'balance_after' => 400,
            'reference_type' => Comic::class,
            'reference_id' => $this->comic->id,
            'description' => 'Mua truyện VIP',
            'created_at' => now(),
        ]);

        $this->wallet->balance = 400;
        $this->wallet->save();

        $purchase = ComicPurchase::create([
            'user_id' => $this->reader->id,
            'comic_id' => $this->comic->id,
            'status' => 'active',
            'price_paid' => 100,
            'transaction_id' => $tx->id,
            'purchased_at' => now(),
        ]);

        // List purchases
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/comic-purchases')
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Refund via admin endpoint
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/comic-purchases/refund', [
                'transaction_id' => $tx->id,
                'reason' => 'Độc giả yêu cầu hoàn tiền do truyện ngừng phát hành',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Verify purchase revoked
        $this->assertEquals('revoked', $purchase->fresh()->status);
        $this->assertNotNull($purchase->fresh()->revoked_at);

        // Verify wallet refunded
        $this->assertEquals(500, $this->wallet->fresh()->balance);
    }
}

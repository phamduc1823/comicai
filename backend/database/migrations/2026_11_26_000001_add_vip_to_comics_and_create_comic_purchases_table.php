<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds VIP fields (is_vip, price) to `comics` table and creates `comic_purchases` table.
     */
    public function up(): void
    {
        // 1. Add VIP status and price to comics table if not exists
        Schema::table('comics', function (Blueprint $table) {
            if (! Schema::hasColumn('comics', 'is_vip')) {
                $table->boolean('is_vip')->default(false)->after('status');
            }
            if (! Schema::hasColumn('comics', 'price')) {
                $table->unsignedInteger('price')->default(0)->after('is_vip');
            }
            $table->index(['is_vip', 'approval_status'], 'idx_comics_vip_approval');
        });

        // 2. Create comic_purchases table
        if (! Schema::hasTable('comic_purchases')) {
            Schema::create('comic_purchases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('comic_id')->constrained('comics')->cascadeOnDelete();
                $table->string('status', 20)->default('active'); // 'active', 'revoked'
                $table->unsignedInteger('price_paid');
                $table->foreignId('transaction_id')->nullable()->constrained('coin_transactions')->nullOnDelete();
                $table->foreignId('refund_transaction_id')->nullable()->constrained('coin_transactions')->nullOnDelete();
                $table->unsignedInteger('purchase_count')->default(1);
                $table->timestamp('purchased_at');
                $table->timestamp('revoked_at')->nullable();

                // Unique constraint ensures a user owns at most one active purchase record per comic
                $table->unique(['user_id', 'comic_id'], 'uk_user_comic_purchase');
                $table->index(['user_id', 'status'], 'idx_comic_purchases_user_status');
                $table->index(['comic_id', 'status'], 'idx_comic_purchases_comic_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comic_purchases');

        Schema::table('comics', function (Blueprint $table) {
            $table->dropIndex('idx_comics_vip_approval');
            if (Schema::hasColumn('comics', 'price')) {
                $table->dropColumn('price');
            }
            if (Schema::hasColumn('comics', 'is_vip')) {
                $table->dropColumn('is_vip');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chapter_unlocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chapter_id');
            $table->unsignedBigInteger('comic_id');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('price_paid');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('refund_transaction_id')->nullable();
            $table->unsignedInteger('unlock_count')->default(1);
            $table->timestamp('unlocked_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->foreign('user_id', 'fk_unlocks_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('chapter_id', 'fk_unlocks_chapter')
                  ->references('id')
                  ->on('chapters')
                  ->onDelete('cascade');

            $table->foreign('comic_id', 'fk_unlocks_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('cascade');

            $table->foreign('transaction_id', 'fk_unlocks_tx')
                  ->references('id')
                  ->on('coin_transactions')
                  ->onDelete('cascade');

            $table->foreign('refund_transaction_id', 'fk_unlocks_refund_tx')
                  ->references('id')
                  ->on('coin_transactions')
                  ->onDelete('set null');

            $table->unique(['user_id', 'chapter_id'], 'uk_user_chapter');
            $table->index(['user_id', 'comic_id', 'status'], 'idx_unlocks_user_comic');
            $table->index('status', 'idx_unlocks_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_unlocks');
    }
};

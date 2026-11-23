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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique('uk_wallets_user_id');
            $table->unsignedInteger('balance')->default(0);
            $table->timestamps();

            $table->foreign('user_id', 'fk_wallets_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });

        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wallet_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 30);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('balance_before');
            $table->unsignedInteger('balance_after');
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('description', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('wallet_id', 'fk_coin_tx_wallet')
                  ->references('id')
                  ->on('wallets')
                  ->onDelete('cascade');

            $table->foreign('user_id', 'fk_coin_tx_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->unique(['user_id', 'idempotency_key'], 'uk_user_idempotency');
            $table->index(['wallet_id', 'created_at'], 'idx_tx_wallet');
            $table->index(['user_id', 'type', 'created_at'], 'idx_tx_user_type');
            $table->index(['reference_type', 'reference_id'], 'idx_tx_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');
        Schema::dropIfExists('wallets');
    }
};

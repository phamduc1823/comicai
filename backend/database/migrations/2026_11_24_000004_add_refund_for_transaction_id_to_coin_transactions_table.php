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
        Schema::table('coin_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('refund_for_transaction_id')
                ->nullable()
                ->after('reference_id');

            $table->unique('refund_for_transaction_id', 'uk_coin_tx_refund_for');

            $table->foreign('refund_for_transaction_id', 'fk_coin_tx_refund_for')
                ->references('id')
                ->on('coin_transactions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coin_transactions', function (Blueprint $table) {
            $table->dropForeign('fk_coin_tx_refund_for');
            $table->dropUnique('uk_coin_tx_refund_for');
            $table->dropColumn('refund_for_transaction_id');
        });
    }
};

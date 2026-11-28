<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('status', 20)->default('accepted')->after('type');
            $table->unsignedBigInteger('initiator_id')->nullable()->after('status');

            $table->foreign('initiator_id', 'fk_conversations_initiator')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');

            $table->index(['status', 'initiator_id'], 'idx_conversations_status_initiator');
        });

        // Migrate existing conversations status & initiator
        try {
            $conversations = DB::table('conversations')->get();
            foreach ($conversations as $conv) {
                $firstMsg = DB::table('messages')
                    ->where('conversation_id', $conv->id)
                    ->orderBy('id', 'asc')
                    ->first();

                $initiatorId = $firstMsg?->sender_id;

                $distinctSendersCount = DB::table('messages')
                    ->where('conversation_id', $conv->id)
                    ->distinct()
                    ->count('sender_id');

                $status = ($distinctSendersCount >= 2 || ! $firstMsg) ? 'accepted' : 'pending';

                DB::table('conversations')
                    ->where('id', $conv->id)
                    ->update([
                        'initiator_id' => $initiatorId,
                        'status' => $status,
                    ]);
            }
        } catch (\Throwable $e) {
            // Safe fallback during testing or fresh installs
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign('fk_conversations_initiator');
            $table->dropIndex('idx_conversations_status_initiator');
            $table->dropColumn(['status', 'initiator_id']);
        });
    }
};

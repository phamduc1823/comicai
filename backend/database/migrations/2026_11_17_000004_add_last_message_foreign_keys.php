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
            $table->foreign('last_message_id')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });

        // In SQLite, altering conversations triggers table rebuild which drops raw constraints.
        // Re-apply the CHECK constraint to ensure it persists in SQLite test environments.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT chk_conversation_type_users CHECK (
                (type = 'direct' AND user_one_id IS NOT NULL AND user_two_id IS NOT NULL AND user_one_id < user_two_id)
                OR
                (type = 'group' AND user_one_id IS NULL AND user_two_id IS NULL)
            )");
        }

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->foreign('last_read_message_id')
                ->references('id')
                ->on('messages')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropForeign(['last_read_message_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['last_message_id']);
        });
    }
};

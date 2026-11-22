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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('direct');
            $table->foreignId('user_one_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('user_two_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['user_one_id', 'user_two_id'], 'unique_direct_pair');
            $table->index('last_message_at');
            $table->index('last_message_id');
        });

        // Add CHECK constraint to enforce direct pair ordering and group NULL rules
        // Compatible with both MySQL 8.0.16+ and SQLite
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT chk_conversation_type_users CHECK (
            (type = 'direct' AND user_one_id IS NOT NULL AND user_two_id IS NOT NULL AND user_one_id < user_two_id)
            OR
            (type = 'group' AND user_one_id IS NULL AND user_two_id IS NULL)
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

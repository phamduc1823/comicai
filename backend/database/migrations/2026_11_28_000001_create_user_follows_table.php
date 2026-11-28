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
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('follower_id');
            $table->unsignedBigInteger('following_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('follower_id', 'fk_user_follows_follower')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('following_id', 'fk_user_follows_following')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->unique(['follower_id', 'following_id'], 'uk_user_follows_pair');
            $table->index(['follower_id', 'created_at'], 'idx_user_follows_follower_created');
            $table->index(['following_id', 'created_at'], 'idx_user_follows_following_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_follows');
    }
};

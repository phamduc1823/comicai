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
        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('likeable_type', 50);
            $table->unsignedBigInteger('likeable_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id', 'fk_likes_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->unique(['user_id', 'likeable_type', 'likeable_id'], 'uk_likes_user_target');
            $table->index(['likeable_type', 'likeable_id'], 'idx_likes_target');
            $table->index(['user_id', 'created_at'], 'idx_likes_user_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('likes');
    }
};

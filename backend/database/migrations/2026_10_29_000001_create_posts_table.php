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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('comic_id')->nullable();
            $table->text('content');
            $table->string('image_path', 255)->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamps();

            $table->foreign('user_id', 'fk_posts_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('comic_id', 'fk_posts_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('set null');

            $table->index('user_id', 'idx_posts_user_id');
            $table->index('comic_id', 'idx_posts_comic_id');
            $table->index('created_at', 'idx_posts_created_at');
            $table->index(['likes_count', 'created_at'], 'idx_posts_popular');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};

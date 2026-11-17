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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('commentable_type', 50);
            $table->unsignedBigInteger('commentable_id');
            $table->text('content');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->timestamps();

            $table->foreign('user_id', 'fk_comments_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('parent_id', 'fk_comments_parent')
                  ->references('id')
                  ->on('comments')
                  ->onDelete('cascade');

            $table->index(['commentable_type', 'commentable_id', 'created_at'], 'idx_comments_target_created');
            $table->index(['parent_id', 'created_at'], 'idx_comments_parent_created');
            $table->index('user_id', 'idx_comments_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};

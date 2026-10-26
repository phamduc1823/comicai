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
        Schema::create('reading_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('comic_id');
            $table->unsignedBigInteger('chapter_id');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('user_id', 'fk_reading_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('comic_id', 'fk_reading_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('cascade');

            $table->foreign('chapter_id', 'fk_reading_chapter')
                  ->references('id')
                  ->on('chapters')
                  ->onDelete('cascade');

            $table->foreign(['comic_id', 'chapter_id'], 'fk_reading_comic_chapter')
                  ->references(['comic_id', 'id'])
                  ->on('chapters')
                  ->onDelete('cascade');

            $table->unique(['user_id', 'comic_id'], 'uk_reading_user_comic');
            $table->index(['user_id', 'updated_at'], 'idx_reading_user_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reading_histories');
    }
};

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
        Schema::create('comic_genre', function (Blueprint $table) {
            $table->unsignedBigInteger('comic_id');
            $table->unsignedBigInteger('genre_id');

            $table->primary(['comic_id', 'genre_id']);

            $table->foreign('comic_id', 'fk_comic_genre_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('cascade');

            $table->foreign('genre_id', 'fk_comic_genre_genre')
                  ->references('id')
                  ->on('genres')
                  ->onDelete('cascade');

            $table->index('genre_id', 'idx_comic_genre_genre_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comic_genre');
    }
};

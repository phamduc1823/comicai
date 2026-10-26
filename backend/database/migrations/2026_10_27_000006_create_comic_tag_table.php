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
        Schema::create('comic_tag', function (Blueprint $table) {
            $table->unsignedBigInteger('comic_id');
            $table->unsignedBigInteger('tag_id');

            $table->primary(['comic_id', 'tag_id']);

            $table->foreign('comic_id', 'fk_comic_tag_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('cascade');

            $table->foreign('tag_id', 'fk_comic_tag_tag')
                  ->references('id')
                  ->on('tags')
                  ->onDelete('cascade');

            $table->index('tag_id', 'idx_comic_tag_tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comic_tag');
    }
};

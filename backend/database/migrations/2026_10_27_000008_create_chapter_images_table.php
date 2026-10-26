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
        Schema::create('chapter_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chapter_id');
            $table->unsignedInteger('page_number');
            $table->string('image_path', 255);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('chapter_id', 'fk_chapter_images_chapter')
                  ->references('id')
                  ->on('chapters')
                  ->onDelete('cascade');

            $table->unique(['chapter_id', 'page_number'], 'uk_chapter_images_page');
            $table->index(['chapter_id', 'page_number'], 'idx_chapter_images_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_images');
    }
};

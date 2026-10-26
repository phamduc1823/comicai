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
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('comic_id');
            $table->decimal('chapter_number', 6, 2);
            $table->string('title', 150)->nullable();
            $table->string('slug', 180);
            $table->string('status', 20)->default('draft');
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();

            $table->foreign('comic_id', 'fk_chapters_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('cascade');

            $table->unique(['comic_id', 'chapter_number'], 'uk_chapters_comic_number');
            $table->unique(['comic_id', 'id'], 'uk_chapters_comic_id');

            $table->index(['comic_id', 'status', 'chapter_number'], 'idx_chapters_comic_status_number');
            $table->index('status', 'idx_chapters_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};

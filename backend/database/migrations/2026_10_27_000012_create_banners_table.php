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
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('comic_id');
            $table->string('title_override', 200)->nullable();
            $table->string('desktop_banner_path', 255);
            $table->string('mobile_banner_path', 255)->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamps();

            $table->foreign('comic_id', 'fk_banners_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('cascade');

            $table->index(['is_active', 'sort_order'], 'idx_banners_active_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};

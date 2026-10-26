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
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('comic_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id', 'fk_follows_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('comic_id', 'fk_follows_comic')
                  ->references('id')
                  ->on('comics')
                  ->onDelete('cascade');

            $table->unique(['user_id', 'comic_id'], 'uk_follows_user_comic');
            $table->index(['user_id', 'created_at'], 'idx_follows_user_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};

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
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
            $table->foreignId('comic_id')->nullable()->after('body')->constrained('comics')->nullOnDelete();
            $table->foreignId('post_id')->nullable()->after('comic_id')->constrained('posts')->nullOnDelete();
            $table->json('images')->nullable()->after('post_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['comic_id']);
            $table->dropForeign(['post_id']);
            $table->dropColumn(['comic_id', 'post_id', 'images']);
            $table->text('body')->nullable(false)->change();
        });
    }
};

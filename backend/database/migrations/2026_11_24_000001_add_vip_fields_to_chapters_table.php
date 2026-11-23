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
        Schema::table('chapters', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->after('status');
            $table->unsignedInteger('price')->default(0)->after('is_vip');

            $table->index('is_vip', 'idx_chapters_vip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropIndex('idx_chapters_vip');
            $table->dropColumn(['is_vip', 'price']);
        });
    }
};

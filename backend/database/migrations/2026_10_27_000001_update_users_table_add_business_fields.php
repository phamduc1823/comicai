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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('reader')->after('password');
            $table->string('avatar_path', 255)->nullable()->after('role');
            $table->tinyInteger('is_active')->default(1)->after('avatar_path');

            $table->index(['role', 'is_active'], 'idx_users_role_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_role_status');
            $table->dropColumn(['role', 'avatar_path', 'is_active']);
        });
    }
};

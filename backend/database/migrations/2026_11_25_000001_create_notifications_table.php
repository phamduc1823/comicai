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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Prevent duplicate notifications for the same event/target per recipient
            $table->unique(
                ['notifiable_type', 'notifiable_id', 'dedupe_key'],
                'notifications_notifiable_dedupe_unique'
            );

            // Fast unread count queries: WHERE notifiable_type = ? AND notifiable_id = ? AND read_at IS NULL
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at'],
                'notifications_notifiable_read_index'
            );

            // Fast paginated chronological listing
            $table->index(
                ['notifiable_type', 'notifiable_id', 'created_at'],
                'notifications_notifiable_created_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

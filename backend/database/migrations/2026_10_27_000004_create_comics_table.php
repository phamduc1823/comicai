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
        Schema::create('comics', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 220)->unique('uk_comics_slug');
            $table->string('alternative_titles', 255)->nullable();
            $table->string('author_name', 150);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_path', 255);
            $table->string('banner_path', 255)->nullable();
            $table->string('age_rating', 10)->default('13+');
            $table->string('status', 20)->default('ongoing');
            $table->string('approval_status', 20)->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('pending_changes')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedInteger('follows_count')->default(0);
            $table->tinyInteger('is_featured')->default(0);
            $table->text('ai_summary')->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->foreign('user_id', 'fk_comics_user')
                  ->references('id')
                  ->on('users')
                  ->onDelete('restrict');

            $table->foreign('approved_by', 'fk_comics_approver')
                  ->references('id')
                  ->on('users')
                  ->onDelete('restrict');

            $table->index('approval_status', 'idx_comics_approval_status');
            $table->index('user_id', 'idx_comics_user_id');
            $table->index(['approval_status', 'status', 'age_rating'], 'idx_comics_public_filter');
            $table->index(['is_featured', 'views_count'], 'idx_comics_featured_views');
            $table->index('created_at', 'idx_comics_created_at');

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['title', 'author_name', 'description'], 'ft_comics_search');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comics');
    }
};

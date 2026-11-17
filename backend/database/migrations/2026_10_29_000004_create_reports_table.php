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
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reporter_id');
            $table->string('reportable_type', 50);
            $table->unsignedBigInteger('reportable_id');
            $table->string('reason', 50);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->foreign('reporter_id', 'fk_reports_reporter')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');

            $table->foreign('resolved_by', 'fk_reports_resolver')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');

            $table->index(['reporter_id', 'reportable_type', 'reportable_id', 'status'], 'idx_reports_reporter_target_status');
            $table->index(['status', 'created_at'], 'idx_reports_status_created');
            $table->index(['reportable_type', 'reportable_id'], 'idx_reports_target');
            $table->index('reporter_id', 'idx_reports_reporter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};

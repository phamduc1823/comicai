<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Table creation order adheres strictly to foreign key dependencies:
     * 1. system_sequences (independent)
     * 2. creator_applications (depends on users)
     * 3. pen_name_reservations (depends on users, creator_applications)
     * 4. creator_profiles (depends on users, creator_applications)
     * 5. creator_application_histories (depends on creator_applications, users)
     * 6. creator_audit_logs (depends on users, creator_applications)
     * 7. pen_name_histories (depends on users)
     */
    public function up(): void
    {
        // 1. Concurrency-safe sequence counter
        Schema::create('system_sequences', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->unsignedBigInteger('current_val')->default(0);
            $table->timestamp('updated_at')->nullable();
        });

        // Initialize sequences for CA-... and CR-...
        DB::table('system_sequences')->insert([
            ['name' => 'creator_application', 'current_val' => 0, 'updated_at' => now()],
            ['name' => 'creator_profile', 'current_val' => 0, 'updated_at' => now()],
        ]);

        // 2. Creator applications table
        Schema::create('creator_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_code', 20)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('pen_name', 50);
            $table->string('pen_name_canonical', 50);
            $table->text('bio');
            $table->text('reason');
            $table->json('sample_works')->nullable();
            $table->string('status', 20)->default('pending'); // 'pending', 'approved', 'rejected', 'canceled'
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_creator_apps_user_status');
            $table->index('status', 'idx_creator_apps_status');
            $table->index('pen_name_canonical', 'idx_creator_apps_canonical');
        });

        // 3. Pen name reservations table (PRIMARY KEY on pen_name_canonical prevents concurrent duplicate reservations)
        Schema::create('pen_name_reservations', function (Blueprint $table) {
            $table->string('pen_name_canonical', 50)->primary();
            $table->string('pen_name', 50);
            $table->foreignId('reserved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reservation_type', 20); // 'application', 'creator', 'alias_creator'
            $table->foreignId('application_id')->nullable()->constrained('creator_applications')->nullOnDelete();
            $table->timestamps();

            $table->index('reserved_by_user_id', 'idx_pen_name_res_user');
        });

        // 4. Official creator profiles table
        Schema::create('creator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('creator_code', 20)->unique();
            $table->string('pen_name', 50);
            $table->string('pen_name_canonical', 50)->unique();
            $table->text('bio')->nullable();
            $table->string('status', 20)->default('active'); // 'active', 'revoked'
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('can_reapply')->default(false);
            $table->timestamp('reapply_granted_at')->nullable();
            $table->foreignId('reapply_granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reapply_grant_reason')->nullable();
            $table->timestamp('reapplied_at')->nullable();
            $table->foreignId('reapplied_application_id')->nullable()->constrained('creator_applications')->nullOnDelete();
            $table->timestamps();

            $table->index('status', 'idx_creator_profiles_status');
            $table->index('pen_name_canonical', 'idx_creator_profiles_canonical');
        });

        // 5. Immutable snapshot history table for application versions
        Schema::create('creator_application_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('creator_applications')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('pen_name', 50);
            $table->text('bio');
            $table->text('reason');
            $table->json('sample_works')->nullable();
            $table->string('status', 20); // 'pending', 'rejected', 'approved', 'canceled'
            $table->text('rejection_reason')->nullable();
            $table->string('action_type', 30); // 'submitted', 'rejected', 'resubmitted', 'approved', 'canceled'
            $table->foreignId('action_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('action_at');
            $table->json('snapshot_data');
            $table->timestamp('created_at')->nullable();

            $table->index(['application_id', 'version'], 'idx_app_histories_app_ver');
        });

        // 6. Creator management audit log timeline table
        Schema::create('creator_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('creator_applications')->nullOnDelete();
            $table->string('event_type', 50);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'event_type'], 'idx_creator_audit_user_event');
            $table->index('event_type', 'idx_creator_audit_event');
        });

        // 7. Pen name change history table
        Schema::create('pen_name_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('old_pen_name', 50);
            $table->string('new_pen_name', 50);
            $table->timestamp('created_at');

            $table->index('user_id', 'idx_pen_name_user');
        });
    }

    /**
     * Reverse the migrations in exact reverse order to respect foreign key constraints.
     */
    public function down(): void
    {
        Schema::dropIfExists('pen_name_histories');
        Schema::dropIfExists('creator_audit_logs');
        Schema::dropIfExists('creator_application_histories');
        Schema::dropIfExists('creator_profiles');
        Schema::dropIfExists('pen_name_reservations');
        Schema::dropIfExists('creator_applications');
        Schema::dropIfExists('system_sequences');
    }
};

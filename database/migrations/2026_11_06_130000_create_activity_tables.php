<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('summary', 300)->nullable();
            $table->text('description');
            $table->string('category', 32);
            $table->string('organizer_name', 160);
            $table->string('venue_name', 160)->nullable();
            $table->string('location_details', 255)->nullable();
            $table->string('audience_scope', 16)->default('all');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('draft');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at'], 'activities_schedule_visibility_index');
            $table->index(['category', 'status'], 'activities_category_status_index');
            $table->index(['audience_scope', 'status'], 'activities_audience_status_index');
            $table->index(['room_id', 'starts_at', 'ends_at'], 'activities_room_schedule_index');
            $table->index(['created_by_user_id', 'created_at'], 'activities_creator_created_index');
        });

        Schema::create('activity_targets', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->restrictOnDelete();
            $table->foreignId('year_level_id')->nullable()->constrained('year_levels')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['activity_id', 'role_id'], 'activity_target_role_unique');
            $table->unique(['activity_id', 'program_id'], 'activity_target_program_unique');
            $table->unique(['activity_id', 'year_level_id'], 'activity_target_year_level_unique');
            $table->unique(['activity_id', 'section_id'], 'activity_target_section_unique');
            $table->unique(['activity_id', 'user_id'], 'activity_target_user_unique');
            $table->index('role_id', 'activity_target_role_index');
            $table->index('program_id', 'activity_target_program_index');
            $table->index('year_level_id', 'activity_target_year_level_index');
            $table->index('section_id', 'activity_target_section_index');
            $table->index('user_id', 'activity_target_user_index');
        });

        Schema::create('activity_history', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->restrictOnDelete();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 16);
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16)->nullable();
            $table->json('previous_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['activity_id', 'created_at'], 'activity_history_activity_index');
            $table->index(['action', 'created_at'], 'activity_history_action_index');
            $table->index('acted_by_user_id', 'activity_history_actor_index');
        });

        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_category_check CHECK (category IN ('seminar', 'workshop', 'orientation', 'meeting', 'competition', 'conference', 'research', 'celebration', 'organization', 'training', 'other'))");
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_audience_scope_check CHECK (audience_scope IN ('all', 'custom'))");
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_status_check CHECK (status IN ('draft', 'published', 'cancelled', 'completed'))");
        DB::statement('ALTER TABLE activities ADD CONSTRAINT activities_schedule_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_cancelled_at_check CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL)");
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_completed_at_check CHECK (status <> 'completed' OR completed_at IS NOT NULL)");
        DB::statement('ALTER TABLE activity_targets ADD CONSTRAINT activity_targets_one_dimension_check CHECK ((role_id IS NOT NULL) + (program_id IS NOT NULL) + (year_level_id IS NOT NULL) + (section_id IS NOT NULL) + (user_id IS NOT NULL) = 1)');
        DB::statement("ALTER TABLE activity_history ADD CONSTRAINT activity_history_action_check CHECK (action IN ('created', 'updated', 'published', 'cancelled', 'completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_history');
        Schema::dropIfExists('activity_targets');
        Schema::dropIfExists('activities');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_snapshots', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('contract_version', 32);
            $table->longText('content_canonical');
            $table->char('content_sha256', 64)->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['academic_term_id', 'version'], 'scheduling_snapshots_term_version_unique');
            $table->index(['academic_term_id', 'created_at'], 'scheduling_snapshots_term_created_index');
        });

        Schema::create('scheduling_generations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('scheduling_snapshot_id')->constrained('scheduling_snapshots')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('solver_status', 20)->nullable();
            $table->string('result_status', 20)->nullable();
            $table->json('generation_scope');
            $table->json('solver_config');
            $table->string('solver_version', 64)->nullable();
            $table->string('contract_version', 32);
            $table->unsignedInteger('solver_execution_ms')->nullable();
            $table->decimal('objective_value', 20, 6)->nullable();
            $table->decimal('best_bound', 20, 6)->nullable();
            $table->boolean('optimality_proven')->nullable();
            $table->boolean('time_limit_reached')->nullable();
            $table->json('unscheduled_mandatory_meeting_ids')->nullable();
            $table->json('response_payload')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('diagnostics')->nullable();
            $table->json('input_metrics')->nullable();
            $table->json('workload_metrics')->nullable();
            $table->json('placement_metrics')->nullable();
            $table->date('retention_until')->nullable();
            $table->uuid('correlation_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['academic_term_id', 'status', 'requested_at'], 'scheduling_generations_term_status_requested_index');
            $table->index('scheduling_snapshot_id', 'scheduling_generations_snapshot_index');
            $table->index(['requested_by_user_id', 'requested_at'], 'scheduling_generations_requester_index');
        });

        Schema::create('scheduling_generation_offerings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('scheduling_generation_id')->constrained('scheduling_generations')->cascadeOnDelete();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->boolean('is_required')->default(true);
            $table->string('inclusion_reason', 32);
            $table->timestamps();

            $table->unique(
                ['scheduling_generation_id', 'subject_offering_id'],
                'scheduling_generation_offerings_generation_offering_unique',
            );
            $table->index('subject_offering_id', 'scheduling_generation_offerings_offering_index');
        });

        Schema::create('scheduling_candidates', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('scheduling_generation_id')->unique()->constrained('scheduling_generations')->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->string('validation_status', 20);
            $table->json('validation_summary')->nullable();
            $table->json('validation_errors')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('previous_candidate_id')->nullable()->constrained('scheduling_candidates')->restrictOnDelete();
            $table->char('snapshot_sha256', 64);
            $table->timestamps();

            $table->unique(['academic_term_id', 'version'], 'scheduling_candidates_term_version_unique');
            $table->index(['academic_term_id', 'status', 'created_at'], 'scheduling_candidates_term_status_created_index');
            $table->index('previous_candidate_id', 'scheduling_candidates_previous_index');
        });

        Schema::create('candidate_meeting_placements', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('scheduling_candidate_id')->constrained('scheduling_candidates')->cascadeOnDelete();
            $table->foreignId('offering_meeting_requirement_id')->constrained('scheduling_meeting_requirements')->restrictOnDelete();
            $table->foreignId('previous_published_schedule_id')->nullable()->constrained('class_schedules')->restrictOnDelete();
            $table->char('meeting_instance_id', 64)->unique();
            $table->unsignedSmallInteger('occurrence_number');
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('meeting_type', 16);
            $table->string('modality', 20);
            $table->boolean('is_primary')->default(true);
            $table->string('status', 20);
            $table->char('previous_meeting_instance_id', 64)->nullable();
            $table->string('placement_change_status', 16);
            $table->unsignedTinyInteger('previous_day_of_week')->nullable();
            $table->time('previous_start_time')->nullable();
            $table->time('previous_end_time')->nullable();
            $table->timestamps();

            $table->unique(
                ['scheduling_candidate_id', 'offering_meeting_requirement_id', 'occurrence_number'],
                'candidate_meeting_placements_requirement_occurrence_unique',
            );
            $table->index(
                ['scheduling_candidate_id', 'day_of_week', 'start_time', 'end_time'],
                'candidate_meeting_placements_schedule_index',
            );
            $table->index(['scheduling_candidate_id', 'status'], 'candidate_meeting_placements_status_index');
            $table->index('previous_published_schedule_id', 'candidate_meeting_placements_previous_schedule_index');
        });

        Schema::create('candidate_meeting_offerings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('candidate_meeting_placement_id')->constrained('candidate_meeting_placements')->cascadeOnDelete();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(
                ['candidate_meeting_placement_id', 'subject_offering_id'],
                'candidate_meeting_offerings_placement_offering_unique',
            );
            $table->unique(['id', 'subject_offering_id'], 'candidate_meeting_offerings_id_offering_unique');
            $table->index('subject_offering_id', 'candidate_meeting_offerings_offering_index');
        });

        Schema::create('candidate_meeting_professors', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('candidate_meeting_placement_id')->constrained('candidate_meeting_placements')->cascadeOnDelete();
            $table->foreignId('candidate_meeting_offering_id')->constrained('candidate_meeting_offerings')->cascadeOnDelete();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->foreignId('faculty_assignment_id')->constrained('faculty_assignments')->restrictOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique('candidate_meeting_offering_id', 'candidate_meeting_professors_offering_row_unique');
            $table->unique(
                ['candidate_meeting_placement_id', 'faculty_assignment_id'],
                'candidate_meeting_professors_placement_assignment_unique',
            );
            $table->index('faculty_assignment_id', 'candidate_meeting_professors_assignment_index');
        });

        Schema::create('candidate_room_allocations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('candidate_meeting_placement_id')->constrained('candidate_meeting_placements')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(
                ['candidate_meeting_placement_id', 'room_id'],
                'candidate_room_allocations_placement_room_unique',
            );
            $table->index(['room_id', 'status'], 'candidate_room_allocations_room_status_index');
            $table->index(
                ['candidate_meeting_placement_id', 'status'],
                'candidate_room_allocations_placement_status_index',
            );
        });

        Schema::create('candidate_status_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('scheduling_candidate_id')->constrained('scheduling_candidates')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(
                ['scheduling_candidate_id', 'created_at'],
                'candidate_status_events_candidate_created_index',
            );
        });

        DB::statement('ALTER TABLE scheduling_snapshots ADD CONSTRAINT scheduling_snapshots_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE scheduling_snapshots ADD CONSTRAINT scheduling_snapshots_hash_check CHECK (CHAR_LENGTH(content_sha256) = 64)');
        DB::statement("ALTER TABLE scheduling_generations ADD CONSTRAINT scheduling_generations_status_check CHECK (status IN ('queued','running','completed','failed','cancelled'))");
        DB::statement("ALTER TABLE scheduling_generations ADD CONSTRAINT scheduling_generations_solver_status_check CHECK (solver_status IS NULL OR solver_status IN ('optimal','feasible','infeasible','unknown','error'))");
        DB::statement("ALTER TABLE scheduling_generations ADD CONSTRAINT scheduling_generations_result_status_check CHECK (result_status IS NULL OR result_status IN ('feasible','infeasible','unknown','time_limit'))");
        DB::statement("ALTER TABLE scheduling_generation_offerings ADD CONSTRAINT scheduling_generation_offerings_reason_check CHECK (inclusion_reason IN ('open_required','published_baseline','fixed','preserved_draft'))");
        DB::statement('ALTER TABLE scheduling_candidates ADD CONSTRAINT scheduling_candidates_version_check CHECK (version > 0)');
        DB::statement("ALTER TABLE scheduling_candidates ADD CONSTRAINT scheduling_candidates_status_check CHECK (status IN ('generated','under_review','approved','rejected','published'))");
        DB::statement("ALTER TABLE scheduling_candidates ADD CONSTRAINT scheduling_candidates_validation_status_check CHECK (validation_status IN ('not_validated','valid','invalid'))");
        DB::statement('ALTER TABLE scheduling_candidates ADD CONSTRAINT scheduling_candidates_hash_check CHECK (CHAR_LENGTH(snapshot_sha256) = 64)');
        DB::statement('ALTER TABLE candidate_meeting_placements ADD CONSTRAINT candidate_meeting_placements_occurrence_check CHECK (occurrence_number > 0)');
        DB::statement('ALTER TABLE candidate_meeting_placements ADD CONSTRAINT candidate_meeting_placements_day_check CHECK (day_of_week BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE candidate_meeting_placements ADD CONSTRAINT candidate_meeting_placements_time_check CHECK (start_time < end_time)');
        DB::statement('ALTER TABLE candidate_meeting_placements ADD CONSTRAINT candidate_meeting_placements_duration_check CHECK (duration_minutes > 0)');
        DB::statement("ALTER TABLE candidate_meeting_placements ADD CONSTRAINT candidate_meeting_placements_modality_check CHECK (modality IN ('face_to_face','online','hybrid'))");
        DB::statement("ALTER TABLE candidate_meeting_placements ADD CONSTRAINT candidate_meeting_placements_status_check CHECK (status IN ('proposed','validated','invalid','approved','published','superseded'))");
        DB::statement("ALTER TABLE candidate_meeting_placements ADD CONSTRAINT candidate_meeting_placements_change_status_check CHECK (placement_change_status IN ('unchanged','moved','new','removed','not_comparable'))");
        DB::statement("ALTER TABLE candidate_room_allocations ADD CONSTRAINT candidate_room_allocations_status_check CHECK (status IN ('proposed','approved','published','released'))");
        DB::statement("ALTER TABLE candidate_status_events ADD CONSTRAINT candidate_status_events_to_status_check CHECK (to_status IN ('generated','under_review','approved','rejected','published'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_status_events');
        Schema::dropIfExists('candidate_room_allocations');
        Schema::dropIfExists('candidate_meeting_professors');
        Schema::dropIfExists('candidate_meeting_offerings');
        Schema::dropIfExists('candidate_meeting_placements');
        Schema::dropIfExists('scheduling_candidates');
        Schema::dropIfExists('scheduling_generation_offerings');
        Schema::dropIfExists('scheduling_generations');
        Schema::dropIfExists('scheduling_snapshots');
    }
};

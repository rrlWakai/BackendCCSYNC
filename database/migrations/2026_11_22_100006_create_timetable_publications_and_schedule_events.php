<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_publications', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('scheduling_candidate_id')->nullable()->unique()->constrained('scheduling_candidates')->restrictOnDelete();
            $table->string('publication_type', 16);
            $table->foreignId('previous_publication_id')->nullable()->constrained('timetable_publications')->restrictOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->timestamp('published_at');
            $table->timestamp('superseded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['academic_term_id', 'status', 'published_at'], 'timetable_publications_term_status_index');
        });

        DB::statement("ALTER TABLE timetable_publications ADD COLUMN active_term_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN academic_term_id ELSE NULL END) STORED");
        DB::statement('ALTER TABLE timetable_publications ADD UNIQUE timetable_publications_active_term_unique (active_term_key)');
        DB::statement("ALTER TABLE timetable_publications ADD CONSTRAINT timetable_publications_type_check CHECK (publication_type IN ('candidate','manual','legacy'))");
        DB::statement("ALTER TABLE timetable_publications ADD CONSTRAINT timetable_publications_status_check CHECK (status IN ('active','superseded'))");
        DB::statement("ALTER TABLE timetable_publications ADD CONSTRAINT timetable_publications_state_check CHECK ((status = 'active' AND superseded_at IS NULL) OR (status = 'superseded' AND superseded_at IS NOT NULL))");

        Schema::table('class_schedules', function (Blueprint $table): void {
            $table->foreignId('timetable_publication_id')->nullable()->after('status')->constrained('timetable_publications')->restrictOnDelete();
            $table->foreignId('candidate_meeting_placement_id')->nullable()->after('timetable_publication_id')->constrained('candidate_meeting_placements')->restrictOnDelete();
            $table->string('meeting_instance_id', 191)->nullable()->after('candidate_meeting_placement_id');
            $table->timestamp('superseded_at')->nullable()->after('meeting_instance_id');
        });

        DB::statement("ALTER TABLE class_schedules ADD COLUMN schedule_scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('draft','published') THEN COALESCE(timetable_publication_id, 0) ELSE NULL END) STORED");
        Schema::table('class_schedules', function (Blueprint $table): void {
            $table->dropUnique('class_schedules_offering_block_unique');
            $table->unique(
                ['schedule_scope_key', 'subject_offering_id', 'day_of_week', 'start_time', 'end_time', 'schedule_type'],
                'class_schedules_scope_block_unique',
            );
            $table->index(['timetable_publication_id', 'status'], 'class_schedules_publication_status_index');
            $table->index('candidate_meeting_placement_id', 'class_schedules_placement_index');
            $table->index(['subject_offering_id', 'status', 'superseded_at'], 'class_schedules_offer_status_superseded_index');
            $table->index('meeting_instance_id', 'class_schedules_instance_index');
        });

        Schema::create('class_schedule_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('timetable_publication_id')->nullable()->constrained('timetable_publications')->restrictOnDelete();
            $table->foreignId('candidate_meeting_placement_id')->nullable()->constrained('candidate_meeting_placements')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['class_schedule_id', 'created_at'], 'class_schedule_events_schedule_created_index');
            $table->index(['academic_term_id', 'created_at'], 'class_schedule_events_term_created_index');
        });

        DB::statement("ALTER TABLE class_schedule_events ADD CONSTRAINT class_schedule_events_type_check CHECK (event_type IN ('created','updated','published','cancelled','superseded','manual_published','manual_released','replaced'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedule_events');

        Schema::table('class_schedules', function (Blueprint $table): void {
            $table->dropUnique('class_schedules_scope_block_unique');
            $table->unique(['subject_offering_id', 'day_of_week', 'start_time', 'end_time', 'schedule_type'], 'class_schedules_offering_block_unique');
            $table->dropForeign(['timetable_publication_id']);
            $table->dropForeign(['candidate_meeting_placement_id']);
            $table->dropIndex('class_schedules_publication_status_index');
            $table->dropIndex('class_schedules_placement_index');
            $table->dropIndex('class_schedules_offer_status_superseded_index');
            $table->dropIndex('class_schedules_instance_index');
        });

        DB::statement('ALTER TABLE class_schedules DROP COLUMN schedule_scope_key');

        Schema::table('class_schedules', function (Blueprint $table): void {
            $table->dropColumn([
                'timetable_publication_id',
                'candidate_meeting_placement_id',
                'meeting_instance_id',
                'superseded_at',
            ]);
        });

        Schema::dropIfExists('timetable_publications');
    }
}
;
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduling_candidates', function (Blueprint $table): void {
            $table->char('source_response_sha256', 64)->nullable()->after('snapshot_sha256');
            $table->foreignId('created_by_user_id')->nullable()->after('source_response_sha256')->constrained('users')->nullOnDelete();
        });

        Schema::create('scheduling_candidate_revisions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('scheduling_candidate_id')->constrained('scheduling_candidates')->cascadeOnDelete();
            $table->foreignId('parent_revision_id')->nullable();
            $table->unsignedInteger('revision_number');
            $table->string('status', 20);
            $table->string('validation_status', 20)->nullable();
            $table->longText('content_canonical');
            $table->char('content_sha256', 64);
            $table->char('base_snapshot_sha256', 64);
            $table->char('base_revision_sha256', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['scheduling_candidate_id', 'revision_number'],
                'scheduling_candidate_revisions_candidate_number_unique',
            );
            $table->index(
                ['scheduling_candidate_id', 'status', 'created_at'],
                'scheduling_candidate_revisions_candidate_status_index',
            );
            $table->index('parent_revision_id', 'scheduling_candidate_revisions_parent_index');
        });

        Schema::table('scheduling_candidate_revisions', function (Blueprint $table): void {
            $table->foreign('parent_revision_id')
                ->references('id')
                ->on('scheduling_candidate_revisions')
                ->nullOnDelete();
        });

        Schema::create('candidate_revision_status_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('scheduling_candidate_revision_id')->constrained('scheduling_candidate_revisions')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->unsignedInteger('expected_version')->nullable();
            $table->unsignedInteger('observed_version');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(
                ['scheduling_candidate_revision_id', 'created_at'],
                'candidate_revision_status_events_revision_created_index',
            );
        });

        Schema::table('candidate_meeting_placements', function (Blueprint $table): void {
            $table->dropUnique('candidate_meeting_placements_meeting_instance_id_unique');
        });

        Schema::table('candidate_meeting_professors', function (Blueprint $table): void {
            $table->dropUnique('candidate_meeting_professors_offering_row_unique');
        });

        DB::statement('ALTER TABLE candidate_meeting_placements MODIFY meeting_instance_id VARCHAR(191) NOT NULL');

        DB::table('candidate_meeting_placements')
            ->select('id', 'uuid')
            ->orderBy('id')
            ->each(function (object $placement): void {
                $uuid = (string) $placement->uuid;

                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) !== 1) {
                    DB::table('candidate_meeting_placements')
                        ->where('id', $placement->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        Schema::table('candidate_meeting_placements', function (Blueprint $table): void {
            $table->unique(
                ['scheduling_candidate_id', 'meeting_instance_id'],
                'candidate_meeting_placements_candidate_instance_unique',
            );
        });

        DB::statement('ALTER TABLE scheduling_candidates ADD CONSTRAINT scheduling_candidates_source_response_hash_check CHECK (source_response_sha256 IS NULL OR CHAR_LENGTH(source_response_sha256) = 64)');
        DB::statement("ALTER TABLE scheduling_candidate_revisions ADD CONSTRAINT scheduling_candidate_revisions_status_check CHECK (status IN ('draft','under_review','validated','needs_revision','superseded','discarded'))");
        DB::statement("ALTER TABLE scheduling_candidate_revisions ADD CONSTRAINT scheduling_candidate_revisions_validation_status_check CHECK (validation_status IS NULL OR validation_status IN ('valid','invalid','not_validated'))");
        DB::statement('ALTER TABLE scheduling_candidate_revisions ADD CONSTRAINT scheduling_candidate_revisions_revision_number_check CHECK (revision_number > 0)');
        DB::statement('ALTER TABLE scheduling_candidate_revisions ADD CONSTRAINT scheduling_candidate_revisions_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE scheduling_candidate_revisions ADD CONSTRAINT scheduling_candidate_revisions_content_hash_check CHECK (CHAR_LENGTH(content_sha256) = 64)');
        DB::statement('ALTER TABLE scheduling_candidate_revisions ADD CONSTRAINT scheduling_candidate_revisions_snapshot_hash_check CHECK (CHAR_LENGTH(base_snapshot_sha256) = 64)');
        DB::statement('ALTER TABLE scheduling_candidate_revisions ADD CONSTRAINT scheduling_candidate_revisions_base_revision_hash_check CHECK (base_revision_sha256 IS NULL OR CHAR_LENGTH(base_revision_sha256) = 64)');
        DB::statement("ALTER TABLE candidate_revision_status_events ADD CONSTRAINT candidate_revision_status_events_to_status_check CHECK (to_status IN ('draft','under_review','validated','needs_revision','superseded','discarded'))");
    }

    public function down(): void
    {
        $duplicateMeetingIds = DB::table('candidate_meeting_placements')
            ->select('meeting_instance_id')
            ->groupBy('meeting_instance_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $longMeetingIds = DB::table('candidate_meeting_placements')
            ->whereRaw('CHAR_LENGTH(meeting_instance_id) > 64')
            ->exists();
        $duplicateProfessorRows = DB::table('candidate_meeting_professors')
            ->select('candidate_meeting_offering_id')
            ->groupBy('candidate_meeting_offering_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateMeetingIds || $longMeetingIds || $duplicateProfessorRows) {
            throw new RuntimeException('Rollback requires manual reconciliation of corrected scheduling candidate data.');
        }

        Schema::table('candidate_meeting_placements', function (Blueprint $table): void {
            $table->dropUnique('candidate_meeting_placements_candidate_instance_unique');
            $table->unique('meeting_instance_id', 'candidate_meeting_placements_meeting_instance_id_unique');
        });

        Schema::table('candidate_meeting_professors', function (Blueprint $table): void {
            $table->unique('candidate_meeting_offering_id', 'candidate_meeting_professors_offering_row_unique');
        });

        DB::statement('ALTER TABLE candidate_meeting_placements MODIFY meeting_instance_id CHAR(64) NOT NULL');

        Schema::dropIfExists('candidate_revision_status_events');
        Schema::dropIfExists('scheduling_candidate_revisions');

        Schema::table('scheduling_candidates', function (Blueprint $table): void {
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn(['source_response_sha256', 'created_by_user_id']);
        });
    }
};

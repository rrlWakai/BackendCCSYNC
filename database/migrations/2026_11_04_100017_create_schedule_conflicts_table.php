<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_conflicts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->foreignId('conflicting_class_schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->string('conflict_type', 16);
            $table->foreignId('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->foreignId('professor_id')->nullable()->constrained('professors')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->unique(['academic_term_id', 'class_schedule_id', 'conflicting_class_schedule_id', 'conflict_type'], 'schedule_conflicts_pair_unique');
            $table->index(['academic_term_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index(['professor_id', 'status']);
            $table->index(['section_id', 'status']);
        });

        DB::statement('ALTER TABLE schedule_conflicts ADD CONSTRAINT schedule_conflicts_pair_check CHECK (class_schedule_id < conflicting_class_schedule_id)');
        DB::statement(<<<'SQL'
ALTER TABLE schedule_conflicts ADD CONSTRAINT schedule_conflicts_resource_check CHECK (
    (conflict_type = 'room' AND room_id IS NOT NULL AND professor_id IS NULL AND section_id IS NULL)
    OR (conflict_type = 'faculty' AND professor_id IS NOT NULL AND room_id IS NULL AND section_id IS NULL)
    OR (conflict_type = 'section' AND section_id IS NOT NULL AND room_id IS NULL AND professor_id IS NULL)
)
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_conflicts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_meeting_plans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['subject_offering_id', 'version'], 'scheduling_meeting_plans_offering_version_unique');
            $table->index(['academic_term_id', 'status'], 'scheduling_meeting_plans_term_status_index');
        });

        Schema::create('scheduling_meeting_requirements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('meeting_plan_id')->constrained('scheduling_meeting_plans')->cascadeOnDelete();
            $table->string('meeting_type', 16);
            $table->unsignedTinyInteger('frequency_per_week');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('modality', 16);
            $table->string('required_room_type', 16)->nullable();
            $table->boolean('mandatory')->default(true);
            $table->json('allowed_days')->nullable();
            $table->json('valid_start_times')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('meeting_plan_id', 'scheduling_meeting_requirements_plan_index');
        });

        DB::statement('ALTER TABLE scheduling_meeting_requirements ADD CONSTRAINT scheduling_meeting_requirements_frequency_check CHECK (frequency_per_week BETWEEN 1 AND 14)');
        DB::statement('ALTER TABLE scheduling_meeting_requirements ADD CONSTRAINT scheduling_meeting_requirements_duration_check CHECK (duration_minutes BETWEEN 1 AND 600)');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_meeting_requirements');
        Schema::dropIfExists('scheduling_meeting_plans');
    }
};

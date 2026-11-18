<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_academic_placements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('year_level_id')->constrained('year_levels')->restrictOnDelete();
            $table->foreignId('major_id')->nullable()->constrained('majors')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->boolean('is_current')->default(false);
            $table->date('effective_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('promotion_run_id')->nullable()->constrained('promotion_runs')->nullOnDelete();
            $table->foreignId('promoted_from_placement_id')
                ->nullable()
                ->constrained('student_academic_placements')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'academic_term_id'], 'student_placements_term_unique');
            $table->index(['academic_year_id', 'year_level_id'], 'student_placements_year_level_index');
            $table->index(['section_id', 'status'], 'student_placements_section_index');
            $table->index(['student_id', 'is_current'], 'student_placements_current_index');
            $table->index(['program_id', 'year_level_id', 'major_id'], 'student_placements_program_level_major_index');
        });

        DB::statement('ALTER TABLE student_academic_placements ADD CONSTRAINT student_placements_effective_range_check CHECK (effective_on IS NULL OR ends_on IS NULL OR effective_on <= ends_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_placements');
    }
};

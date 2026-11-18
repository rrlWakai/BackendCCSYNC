<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_major_selections', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('major_id')->constrained('majors')->restrictOnDelete();
            $table->foreignId('period_id')
                ->nullable()
                ->constrained('major_selection_periods')
                ->nullOnDelete();
            $table->string('status', 16)->default('submitted');
            $table->timestamp('selected_at')->useCurrent();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id'], 'student_major_selections_student_year_unique');
            $table->index(['academic_year_id', 'program_id', 'status'], 'student_major_selections_year_program_index');
            $table->index(['major_id', 'status'], 'student_major_selections_major_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_major_selections');
    }
};

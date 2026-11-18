<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_run_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('promotion_run_id')
                ->constrained('promotion_runs')
                ->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('from_placement_id')
                ->nullable()
                ->constrained('student_academic_placements')
                ->nullOnDelete();
            $table->foreignId('from_year_level_id')->constrained('year_levels')->restrictOnDelete();
            $table->foreignId('to_year_level_id')->nullable()->constrained('year_levels')->restrictOnDelete();
            $table->foreignId('major_id')->nullable()->constrained('majors')->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('exception_code', 48)->nullable();
            $table->text('exception_message')->nullable();
            $table->timestamps();

            $table->unique(['promotion_run_id', 'student_id'], 'promotion_run_items_run_student_unique');
            $table->index(['status', 'to_year_level_id'], 'promotion_run_items_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_run_items');
    }
};

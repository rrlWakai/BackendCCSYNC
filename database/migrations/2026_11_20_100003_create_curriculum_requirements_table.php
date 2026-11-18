<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_requirements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->foreignId('year_level_id')->constrained('year_levels')->restrictOnDelete();
            $table->foreignId('major_id')->nullable()->constrained('majors')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('requirement_type', 24)->default('core');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(
                ['program_id', 'year_level_id', 'major_id', 'subject_id'],
                'curriculum_requirements_scope_unique',
            );
            $table->index(['program_id', 'year_level_id', 'status'], 'curriculum_requirements_level_index');
            $table->index(['major_id', 'status'], 'curriculum_requirements_major_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_requirements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_offerings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->unsignedSmallInteger('expected_students')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->unique(['academic_term_id', 'section_id', 'subject_id'], 'subject_offerings_combination_unique');
            $table->index(['section_id', 'academic_term_id']);
            $table->index(['subject_id', 'academic_term_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_offerings');
    }
};

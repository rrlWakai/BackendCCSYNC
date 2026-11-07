<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_assignments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->cascadeOnDelete();
            $table->foreignId('faculty_assignment_id')->constrained('faculty_assignments')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['class_schedule_id', 'faculty_assignment_id'], 'schedule_faculty_assignment_unique');
            $table->index(['faculty_assignment_id', 'status']);
            $table->index(['class_schedule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_assignments');
    }
};

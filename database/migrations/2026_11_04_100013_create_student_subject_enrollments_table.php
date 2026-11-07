<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subject_enrollments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('subject_offering_id')->constrained('subject_offerings')->restrictOnDelete();
            $table->string('status', 16)->default('enrolled');
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('dropped_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'subject_offering_id'], 'student_offering_enrollment_unique');
            $table->index(['subject_offering_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        DB::statement('ALTER TABLE student_subject_enrollments ADD CONSTRAINT enrollments_dropped_after_enrolled_check CHECK (dropped_at IS NULL OR dropped_at >= enrolled_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subject_enrollments');
    }
};

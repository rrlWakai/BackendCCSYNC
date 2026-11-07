<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_personnel_programs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'program_id'], 'department_personnel_program_unique');
            $table->index('program_id');
        });

        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('class_schedule_id')->constrained('class_schedules')->restrictOnDelete();
            $table->date('class_date');
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['class_schedule_id', 'class_date'], 'attendance_schedule_date_unique');
            $table->index(['status', 'class_date']);
        });

        Schema::create('student_attendance', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('attendance_session_id')->constrained('attendance_sessions')->restrictOnDelete();
            $table->foreignId('student_subject_enrollment_id')
                ->constrained('student_subject_enrollments')
                ->restrictOnDelete();
            $table->string('status', 16);
            $table->time('check_in_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['attendance_session_id', 'student_subject_enrollment_id'],
                'attendance_session_enrollment_unique',
            );
            $table->index(['attendance_session_id', 'status']);
        });

        Schema::create('attendance_record_changes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('student_attendance_id')->constrained('student_attendance')->restrictOnDelete();
            $table->string('previous_status', 16);
            $table->string('new_status', 16);
            $table->time('previous_check_in_at')->nullable();
            $table->time('new_check_in_at')->nullable();
            $table->text('previous_remarks')->nullable();
            $table->text('new_remarks')->nullable();
            $table->text('correction_reason');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['student_attendance_id', 'changed_at'], 'attendance_record_changes_history_index');
        });

        DB::statement("ALTER TABLE attendance_sessions ADD CONSTRAINT attendance_sessions_status_check CHECK (status IN ('open', 'submitted', 'cancelled'))");
        DB::statement("ALTER TABLE student_attendance ADD CONSTRAINT student_attendance_status_check CHECK (status IN ('present', 'absent', 'late', 'excused'))");
        DB::statement("ALTER TABLE attendance_record_changes ADD CONSTRAINT attendance_changes_previous_status_check CHECK (previous_status IN ('present', 'absent', 'late', 'excused'))");
        DB::statement("ALTER TABLE attendance_record_changes ADD CONSTRAINT attendance_changes_new_status_check CHECK (new_status IN ('present', 'absent', 'late', 'excused'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_record_changes');
        Schema::dropIfExists('student_attendance');
        Schema::dropIfExists('attendance_sessions');
        Schema::dropIfExists('department_personnel_programs');
    }
};

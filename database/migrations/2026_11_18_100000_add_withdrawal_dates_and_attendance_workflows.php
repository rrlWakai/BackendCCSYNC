<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_subject_enrollments', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable()->after('dropped_at');
        });

        DB::table('student_subject_enrollments')
            ->where('status', 'dropped')
            ->whereNull('dropped_at')
            ->update(['dropped_at' => DB::raw('updated_at')]);

        DB::table('student_subject_enrollments')
            ->where('status', 'withdrawn')
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => DB::raw('updated_at')]);

        Schema::create('attendance_excuse_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('attendance_session_id')->constrained('attendance_sessions')->restrictOnDelete();
            $table->foreignId('student_subject_enrollment_id')
                ->constrained('student_subject_enrollments')
                ->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['attendance_session_id', 'student_subject_enrollment_id'],
                'attendance_excuse_session_enrollment_unique',
            );
            $table->index(['status', 'attendance_session_id'], 'attendance_excuse_status_session_index');
        });

        Schema::create('attendance_excuse_request_changes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('attendance_excuse_request_id')
                ->constrained('attendance_excuse_requests')
                ->restrictOnDelete();
            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->text('reason');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(
                ['attendance_excuse_request_id', 'changed_at'],
                'attendance_excuse_changes_history_index',
            );
        });

        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->restrictOnDelete();
            $table->foreignId('subject_offering_id')->nullable()->constrained('subject_offerings')->restrictOnDelete();
            $table->unsignedSmallInteger('allowed_absences');
            $table->timestamp('effective_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['academic_term_id', 'subject_offering_id', 'effective_at'],
                'attendance_policies_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policies');
        Schema::dropIfExists('attendance_excuse_request_changes');
        Schema::dropIfExists('attendance_excuse_requests');
        Schema::table('student_subject_enrollments', function (Blueprint $table) {
            $table->dropColumn('withdrawn_at');
        });
    }
};

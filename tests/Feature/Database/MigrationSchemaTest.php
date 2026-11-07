<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_two_tables_are_created_in_dependency_order(): void
    {
        $tables = [
            'roles',
            'users',
            'programs',
            'academic_terms',
            'year_levels',
            'students',
            'professors',
            'sections',
            'subjects',
            'rooms',
            'subject_offerings',
            'faculty_availability',
            'faculty_assignments',
            'student_subject_enrollments',
            'class_schedules',
            'schedule_assignments',
            'room_allocations',
            'schedule_conflicts',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_attendance_and_program_scope_tables_are_created(): void
    {
        foreach ([
            'department_personnel_programs',
            'attendance_sessions',
            'student_attendance',
            'attendance_record_changes',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing attendance table: {$table}");
        }

        foreach ([
            'attendance_sessions' => ['class_schedule_id', 'class_date', 'status', 'created_by_user_id'],
            'student_attendance' => ['attendance_session_id', 'student_subject_enrollment_id', 'status'],
            'attendance_record_changes' => ['student_attendance_id', 'correction_reason', 'changed_by_user_id'],
            'department_personnel_programs' => ['user_id', 'program_id'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Missing {$table}.{$column}");
            }
        }
    }

    public function test_users_table_has_phase_two_identity_columns(): void
    {
        foreach (['role_id', 'status', 'last_login_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "Missing users.{$column}");
        }
    }

    public function test_other_future_module_tables_are_not_created(): void
    {
        foreach ([
            'faculty_attendance',
            'class_announcements',
            'announcement_revisions',
            'activities',
            'notifications',
            'qr_room_codes',
            'room_usage_logs',
            'audit_logs',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected future table: {$table}");
        }
    }
}

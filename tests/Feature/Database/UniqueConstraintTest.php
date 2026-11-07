<?php

namespace Tests\Feature\Database;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentSubjectEnrollment;
use App\Models\SubjectOffering;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class UniqueConstraintTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_role_code_is_unique(): void
    {
        Role::create(['code' => 'administrator', 'name' => 'Administrator']);

        $this->assertQueryFails(fn () => Role::create([
            'code' => 'administrator',
            'name' => 'Duplicate',
        ]));
    }

    public function test_user_email_is_unique(): void
    {
        $role = $this->makeRole('student');
        User::create([
            'role_id' => $role->id,
            'name' => 'First',
            'email' => 'duplicate@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);

        $this->assertQueryFails(fn () => User::create([
            'role_id' => $role->id,
            'name' => 'Second',
            'email' => 'duplicate@example.test',
            'password' => 'password',
            'status' => 'active',
        ]));
    }

    public function test_student_and_employee_numbers_are_unique(): void
    {
        $program = $this->makeProgram();
        $student = $this->makeStudent($this->makeUser('student'), $program);
        $professor = $this->makeProfessor($this->makeUser('professor'), $program);

        $this->assertQueryFails(fn () => Student::create([
            'user_id' => $this->makeUser('student')->id,
            'student_number' => $student->student_number,
            'program_id' => $program->id,
            'status' => 'active',
        ]));

        $this->assertQueryFails(fn () => Professor::create([
            'user_id' => $this->makeUser('professor')->id,
            'employee_number' => $professor->employee_number,
            'program_id' => $program->id,
            'employment_type' => 'full_time',
            'status' => 'active',
        ]));
    }

    public function test_section_code_is_unique_within_a_term(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term, null, ['code' => 'BSIT-1A']);

        $this->assertQueryFails(fn () => $this->makeSection($program, $term, null, ['code' => $section->code]));
    }

    public function test_subject_offering_combination_is_unique(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $this->makeOffering($term, $section, $subject);

        $this->assertQueryFails(fn () => SubjectOffering::create([
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'status' => 'open',
        ]));
    }

    public function test_student_cannot_enroll_twice_in_the_same_offering(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $student = $this->makeStudent($this->makeUser('student'), $program);

        StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $this->assertQueryFails(fn () => StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]));
    }

    public function test_class_schedule_block_is_unique_per_offering(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $this->makeClassSchedule($offering);

        $this->assertQueryFails(fn () => ClassSchedule::create([
            'subject_offering_id' => $offering->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'schedule_type' => 'lecture',
            'modality' => 'face_to_face',
            'status' => 'published',
        ]));
    }

    public function test_academic_year_and_term_pair_is_unique(): void
    {
        $term = $this->makeAcademicTerm();

        $this->assertQueryFails(fn () => AcademicTerm::create([
            'academic_year' => $term->academic_year,
            'term' => $term->term,
            'name' => 'Duplicate Term',
            'starts_on' => '2026-08-03',
            'ends_on' => '2026-12-18',
            'is_current' => false,
            'status' => 'planned',
        ]));
    }
}

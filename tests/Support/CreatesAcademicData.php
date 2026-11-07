<?php

namespace Tests\Support;

use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\FacultyAssignment;
use App\Models\Professor;
use App\Models\Program;
use App\Models\Role;
use App\Models\Room;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Models\YearLevel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait CreatesAcademicData
{
    protected function makeRole(string $code): Role
    {
        return Role::firstOrCreate(
            ['code' => $code],
            ['name' => Str::headline($code)],
        );
    }

    protected function makeUser(string $roleCode, array $attributes = []): User
    {
        $role = $this->makeRole($roleCode);

        return User::create(array_merge([
            'role_id' => $role->id,
            'name' => fake()->name(),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ], $attributes));
    }

    protected function makeProgram(): Program
    {
        $code = 'P'.Str::upper(Str::random(6));

        return Program::create([
            'code' => $code,
            'name' => 'Program '.$code,
            'status' => 'active',
        ]);
    }

    protected function makeAcademicTerm(bool $current = true): AcademicTerm
    {
        return AcademicTerm::create([
            'academic_year' => '2026-2027',
            'term' => $current ? 'first' : 'second',
            'name' => $current ? 'First Semester' : 'Second Semester',
            'starts_on' => '2026-08-03',
            'ends_on' => '2026-12-18',
            'is_current' => $current,
            'status' => $current ? 'active' : 'planned',
        ]);
    }

    protected function makeYearLevel(int $level = 1): YearLevel
    {
        return YearLevel::firstOrCreate(
            ['level' => $level],
            ['name' => 'Year '.$level],
        );
    }

    protected function makeSection(
        Program $program,
        AcademicTerm $term,
        ?YearLevel $yearLevel = null,
        array $attributes = [],
    ): Section {
        $yearLevel ??= $this->makeYearLevel();
        $code = 'SEC-'.Str::upper(Str::random(6));

        return Section::create(array_merge([
            'program_id' => $program->id,
            'year_level_id' => $yearLevel->id,
            'academic_term_id' => $term->id,
            'code' => $code,
            'name' => 'Section '.$code,
            'capacity' => 40,
            'status' => 'active',
        ], $attributes));
    }

    protected function makeSubject(array $attributes = []): Subject
    {
        $code = 'SUB'.Str::upper(Str::random(6));

        return Subject::create(array_merge([
            'code' => $code,
            'title' => 'Subject '.$code,
            'units' => 3.0,
            'lecture_hours' => 2.0,
            'lab_hours' => 3.0,
            'status' => 'active',
        ], $attributes));
    }

    protected function makeOffering(
        AcademicTerm $term,
        Section $section,
        Subject $subject,
        array $attributes = [],
    ): SubjectOffering {
        return SubjectOffering::create(array_merge([
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'expected_students' => 40,
            'status' => 'open',
        ], $attributes));
    }

    protected function makeStudent(User $user, Program $program): Student
    {
        return Student::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.Str::upper(Str::random(8)),
            'program_id' => $program->id,
            'admitted_on' => '2026-08-03',
            'status' => 'active',
        ]);
    }

    protected function makeProfessor(User $user, Program $program): Professor
    {
        return Professor::create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-'.Str::upper(Str::random(8)),
            'program_id' => $program->id,
            'employment_type' => 'full_time',
            'status' => 'active',
        ]);
    }

    protected function makeRoom(array $attributes = []): Room
    {
        $code = 'ROOM-'.Str::upper(Str::random(6));

        return Room::create(array_merge([
            'code' => $code,
            'name' => 'Room '.$code,
            'building' => 'Main',
            'floor' => '1',
            'capacity' => 40,
            'room_type' => 'lecture',
            'status' => 'available',
        ], $attributes));
    }

    protected function makeFacultyAssignment(
        Professor $professor,
        SubjectOffering $offering,
        array $attributes = [],
    ): FacultyAssignment {
        return FacultyAssignment::create(array_merge([
            'professor_id' => $professor->id,
            'subject_offering_id' => $offering->id,
            'assignment_role' => 'lead',
            'status' => 'active',
        ], $attributes));
    }

    protected function makeClassSchedule(SubjectOffering $offering, array $attributes = []): ClassSchedule
    {
        return ClassSchedule::create(array_merge([
            'subject_offering_id' => $offering->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'schedule_type' => 'lecture',
            'modality' => 'face_to_face',
            'status' => 'published',
        ], $attributes));
    }
}

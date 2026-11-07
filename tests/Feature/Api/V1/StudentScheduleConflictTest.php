<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\Role;
use App\Models\RoomAllocation;
use App\Models\ScheduleAssignment;
use App\Models\StudentSubjectEnrollment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class StudentScheduleConflictTest extends TestCase
{
    use CreatesAcademicData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_admin_detects_overlapping_enrollments_across_sections_and_year_levels_with_details(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $studentUser = $this->makeUser(Role::STUDENT);
        $student = $this->makeStudent($studentUser, $program);
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);

        $first = $this->makeEnrolledClass($student, $program, $term, 1, 'CS101', [
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);
        $second = $this->makeEnrolledClass($student, $program, $term, 2, 'CS202', [
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
        $room = $this->makeRoom();
        RoomAllocation::create([
            'class_schedule_id' => $first['schedule']->id,
            'room_id' => $room->id,
            'status' => 'active',
            'allocated_at' => now(),
            'released_at' => null,
        ]);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $first['offering']);
        ScheduleAssignment::create([
            'class_schedule_id' => $first['schedule']->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.summary.students_checked', 1)
            ->assertJsonPath('data.summary.students_with_conflicts', 1)
            ->assertJsonPath('data.summary.conflict_pairs', 1)
            ->assertJsonPath('data.conflicts.0.student.student_number', $student->student_number)
            ->assertJsonPath('data.conflicts.0.academic_term.id', $term->id)
            ->assertJsonPath('data.conflicts.0.day_label', 'Monday')
            ->assertJsonPath('data.conflicts.0.overlap.start_time', '09:00:00')
            ->assertJsonPath('data.conflicts.0.overlap.end_time', '10:00:00')
            ->assertJsonPath('data.conflicts.0.classes.0.section.year_level.id', $first['section']->year_level_id)
            ->assertJsonPath('data.conflicts.0.classes.1.section.year_level.id', $second['section']->year_level_id)
            ->assertJsonPath('data.conflicts.0.classes.0.room.id', $room->id)
            ->assertJsonPath('data.conflicts.0.classes.0.professors.0.name', $professor->user->name);
    }

    public function test_back_to_back_and_different_day_classes_are_not_conflicts(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $student = $this->makeStudent($this->makeUser(Role::STUDENT), $program);

        $this->makeEnrolledClass($student, $program, $term, 1, 'CS301', [
            'start_time' => '07:00:00',
            'end_time' => '09:00:00',
            'day_of_week' => 1,
        ]);
        $this->makeEnrolledClass($student, $program, $term, 2, 'CS302', [
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'day_of_week' => 1,
        ]);
        $this->makeEnrolledClass($student, $program, $term, 3, 'CS303', [
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'day_of_week' => 2,
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.summary.students_checked', 1)
            ->assertJsonPath('data.summary.conflict_pairs', 0);
    }

    public function test_three_overlapping_subjects_produce_the_three_unique_pairs_once(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $student = $this->makeStudent($this->makeUser(Role::STUDENT), $program);

        foreach (['CS401', 'CS402', 'CS403'] as $index => $code) {
            $this->makeEnrolledClass($student, $program, $term, $index + 1, $code, [
                'start_time' => '08:00:00',
                'end_time' => '10:00:00',
            ]);
        }

        $response = $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.summary.conflict_pairs', 3)
            ->assertJsonCount(3, 'data.conflicts');

        $ids = collect($response->json('data.conflicts'))->pluck('id');
        $this->assertCount(3, $ids->unique());
    }

    public function test_other_terms_dropped_enrollments_and_cancelled_schedules_are_excluded(): void
    {
        $term = $this->makeAcademicTerm();
        $otherTerm = AcademicTerm::create([
            'academic_year' => '2025-2026',
            'term' => 'second',
            'name' => 'Previous Semester',
            'starts_on' => '2026-01-05',
            'ends_on' => '2026-05-15',
            'is_current' => false,
            'status' => 'completed',
        ]);
        $program = $this->makeProgram();
        $student = $this->makeStudent($this->makeUser(Role::STUDENT), $program);

        $this->makeEnrolledClass($student, $program, $term, 1, 'CS501', [
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);
        $this->makeEnrolledClass($student, $program, $term, 2, 'CS502', [
            'status' => 'dropped',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
        $this->makeEnrolledClass($student, $program, $term, 3, 'CS503', [
            'schedule_status' => 'cancelled',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
        $this->makeEnrolledClass($student, $program, $term, 4, 'CS505', [
            'offering_status' => 'closed',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);
        $this->makeEnrolledClass($student, $program, $otherTerm, 4, 'CS504', [
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.summary.conflict_pairs', 0);
    }

    public function test_administrator_can_view_collegewide_results_but_department_personnel_are_scoped(): void
    {
        $term = $this->makeAcademicTerm();
        $assignedProgram = $this->makeProgram();
        $outsideProgram = $this->makeProgram();
        $this->makeStudentWithOverlap($assignedProgram, $term, 'STU-ASSIGNED');
        $this->makeStudentWithOverlap($outsideProgram, $term, 'STU-OUTSIDE');

        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $this->actingAs($administrator)
            ->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.summary.students_checked', 2)
            ->assertJsonPath('data.summary.conflict_pairs', 2);

        $personnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $personnel->departmentPersonnelPrograms()->attach($assignedProgram->id);
        $this->actingAs($personnel)
            ->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.summary.students_checked', 1)
            ->assertJsonPath('data.summary.conflict_pairs', 1)
            ->assertJsonPath('data.conflicts.0.student.name', 'STU-ASSIGNED');
        $this->getJson('/api/v1/student-schedule-conflicts/options?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.programs')
            ->assertJsonPath('data.programs.0.id', $assignedProgram->id);
        $this->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id.'&program_id='.$outsideProgram->id)
            ->assertForbidden();
    }

    public function test_department_personnel_cannot_filter_by_an_out_of_scope_section(): void
    {
        $term = $this->makeAcademicTerm();
        $assignedProgram = $this->makeProgram();
        $outsideProgram = $this->makeProgram();
        $this->makeStudentWithOverlap($assignedProgram, $term, 'STU-IN-SCOPE');
        $outside = $this->makeStudentWithOverlap($outsideProgram, $term, 'STU-OUT-SCOPE');
        $personnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $personnel->departmentPersonnelPrograms()->attach($assignedProgram->id);

        $this->actingAs($personnel)
            ->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id.'&section_id='.$outside['section']->id)
            ->assertForbidden();
    }

    public function test_student_can_only_get_own_conflicts_and_admin_report_is_forbidden(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $firstUser = $this->makeUser(Role::STUDENT);
        $firstStudent = $this->makeStudent($firstUser, $program);
        $secondUser = $this->makeUser(Role::STUDENT);
        $this->makeStudent($secondUser, $program);
        $this->makeStudentOverlapClasses($firstStudent, $program, $term);
        $this->makeStudentOverlapClasses($secondUser->student, $program, $term);

        $this->actingAs($firstUser)
            ->getJson('/api/v1/student-schedule-conflicts/me')
            ->assertOk()
            ->assertJsonPath('data.summary.students_checked', 1)
            ->assertJsonPath('data.summary.conflict_pairs', 1)
            ->assertJsonPath('data.conflicts.0.student.id', $firstStudent->id);
        $this->getJson('/api/v1/student-schedule-conflicts?academic_term_id='.$term->id)->assertForbidden();
        $this->getJson('/api/v1/student-schedule-conflicts/me?student_id='.$secondUser->student->id)
            ->assertOk()
            ->assertJsonPath('data.conflicts.0.student.id', $firstStudent->id);
    }

    public function test_professors_cannot_access_administrative_or_student_conflict_reports(): void
    {
        $professor = $this->makeUser(Role::PROFESSOR);
        $this->actingAs($professor)
            ->getJson('/api/v1/student-schedule-conflicts')
            ->assertForbidden();
        $this->getJson('/api/v1/student-schedule-conflicts/options')->assertForbidden();
        $this->getJson('/api/v1/student-schedule-conflicts/me')->assertForbidden();
    }

    public function test_admin_filters_conflicts_by_student_search_program_year_level_and_section(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $student = $this->makeStudent($this->makeUser(Role::STUDENT, ['name' => 'Conflict Candidate']), $program);
        $first = $this->makeEnrolledClass($student, $program, $term, 1, 'CS801', ['start_time' => '08:00:00', 'end_time' => '10:00:00']);
        $this->makeEnrolledClass($student, $program, $term, 2, 'CS802', ['start_time' => '09:00:00', 'end_time' => '11:00:00']);

        $url = '/api/v1/student-schedule-conflicts?academic_term_id='.$term->id
            .'&program_id='.$program->id
            .'&year_level_id='.$first['section']->year_level_id
            .'&section_id='.$first['section']->id
            .'&search=Conflict%20Candidate';
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.summary.conflict_pairs', 1);
    }

    private function makeStudentWithOverlap($program, $term, string $studentNumber): array
    {
        $student = $this->makeStudent(
            $this->makeUser(Role::STUDENT, ['name' => $studentNumber]),
            $program,
        );
        $classes = $this->makeStudentOverlapClasses($student, $program, $term);

        return [
            'student' => $student,
            'section' => $classes[0]['section'],
        ];
    }

    private function makeStudentOverlapClasses($student, $program, $term): array
    {
        $suffix = Str::upper(Str::random(5));

        return [
            $this->makeEnrolledClass($student, $program, $term, 1, 'OVLPA'.$suffix, [
                'start_time' => '08:00:00',
                'end_time' => '10:00:00',
            ]),
            $this->makeEnrolledClass($student, $program, $term, 2, 'OVLPB'.$suffix, [
                'start_time' => '09:00:00',
                'end_time' => '11:00:00',
            ]),
        ];
    }

    private function makeEnrolledClass($student, $program, $term, int $year, string $subjectCode, array $overrides = []): array
    {
        $yearLevel = $this->makeYearLevel($year);
        $section = $this->makeSection($program, $term, $yearLevel);
        $subject = $this->makeSubject([
            'code' => $subjectCode,
            'title' => 'Subject '.$subjectCode,
        ]);
        $offering = $this->makeOffering($term, $section, $subject, [
            'status' => $overrides['offering_status'] ?? 'open',
        ]);
        $enrollment = StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => $overrides['status'] ?? 'enrolled',
            'enrolled_at' => '2026-08-03 08:00:00',
            'dropped_at' => ($overrides['status'] ?? 'enrolled') === 'dropped' ? '2026-08-04 08:00:00' : null,
        ]);
        $scheduleAttributes = [
            'status' => $overrides['schedule_status'] ?? 'published',
            'day_of_week' => $overrides['day_of_week'] ?? 1,
            'start_time' => $overrides['start_time'] ?? '08:00:00',
            'end_time' => $overrides['end_time'] ?? '10:00:00',
        ];
        $schedule = $this->makeClassSchedule($offering, $scheduleAttributes);

        return compact('enrollment', 'offering', 'schedule', 'section', 'subject');
    }
}

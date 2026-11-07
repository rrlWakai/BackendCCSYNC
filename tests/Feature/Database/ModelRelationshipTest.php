<?php

namespace Tests\Feature\Database;

use App\Models\RoomAllocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleConflict;
use App\Models\StudentSubjectEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_user_role_and_profile_relationships(): void
    {
        $program = $this->makeProgram();
        $studentUser = $this->makeUser('student');
        $student = $this->makeStudent($studentUser, $program);
        $professorUser = $this->makeUser('professor');
        $professor = $this->makeProfessor($professorUser, $program);

        $this->assertSame('student', $studentUser->role->code);
        $this->assertTrue($studentUser->student->is($student));
        $this->assertNull($studentUser->professor);
        $this->assertSame('professor', $professorUser->role->code);
        $this->assertTrue($professorUser->professor->is($professor));
        $this->assertTrue($student->user->is($studentUser));
        $this->assertTrue($professor->user->is($professorUser));
        $this->assertTrue($student->program->is($program));
        $this->assertTrue($professor->program->is($program));
        $this->assertCount(1, $studentUser->role->users);
    }

    public function test_academic_structure_relationships(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $yearLevel = $this->makeYearLevel();
        $section = $this->makeSection($program, $term, $yearLevel);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);

        $this->assertTrue($section->program->is($program));
        $this->assertTrue($section->yearLevel->is($yearLevel));
        $this->assertTrue($section->academicTerm->is($term));
        $this->assertTrue($offering->academicTerm->is($term));
        $this->assertTrue($offering->section->is($section));
        $this->assertTrue($offering->subject->is($subject));
        $this->assertTrue($term->sections->contains($section));
        $this->assertTrue($term->subjectOfferings->contains($offering));
        $this->assertTrue($section->subjectOfferings->contains($offering));
        $this->assertTrue($subject->subjectOfferings->contains($offering));
    }

    public function test_enrollment_and_faculty_assignment_relationships(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $student = $this->makeStudent($this->makeUser('student'), $program);
        $professor = $this->makeProfessor($this->makeUser('professor'), $program);

        $enrollment = StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);

        $this->assertTrue($enrollment->student->is($student));
        $this->assertTrue($enrollment->subjectOffering->is($offering));
        $this->assertTrue($student->enrollments->contains($enrollment));
        $this->assertTrue($student->subjectOfferings->contains($offering));
        $this->assertTrue($offering->enrollments->contains($enrollment));
        $this->assertTrue($facultyAssignment->professor->is($professor));
        $this->assertTrue($facultyAssignment->subjectOffering->is($offering));
        $this->assertTrue($professor->facultyAssignments->contains($facultyAssignment));
        $this->assertTrue($offering->facultyAssignments->contains($facultyAssignment));
    }

    public function test_schedule_assignment_and_room_allocation_relationships(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $professor = $this->makeProfessor($this->makeUser('professor'), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $schedule = $this->makeClassSchedule($offering);
        $room = $this->makeRoom();

        $scheduleAssignment = ScheduleAssignment::create([
            'class_schedule_id' => $schedule->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);

        $allocation = RoomAllocation::create([
            'class_schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'status' => 'active',
            'allocated_at' => now(),
        ]);

        $this->assertTrue($schedule->subjectOffering->is($offering));
        $this->assertTrue($schedule->scheduleAssignments->contains($scheduleAssignment));
        $this->assertTrue($schedule->roomAllocations->contains($allocation));
        $this->assertTrue($scheduleAssignment->classSchedule->is($schedule));
        $this->assertTrue($scheduleAssignment->facultyAssignment->is($facultyAssignment));
        $this->assertTrue($facultyAssignment->scheduleAssignments->contains($scheduleAssignment));
        $this->assertTrue($professor->scheduleAssignments->contains($scheduleAssignment));
        $this->assertTrue($allocation->classSchedule->is($schedule));
        $this->assertTrue($allocation->room->is($room));
        $this->assertTrue($room->roomAllocations->contains($allocation));
        $this->assertTrue($offering->classSchedules->contains($schedule));
    }

    public function test_schedule_conflict_relationships(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $room = $this->makeRoom();
        $schedule = $this->makeClassSchedule($offering);
        $conflictingSchedule = $this->makeClassSchedule($offering, [
            'day_of_week' => 2,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);

        $conflict = ScheduleConflict::create([
            'academic_term_id' => $term->id,
            'class_schedule_id' => $schedule->id,
            'conflicting_class_schedule_id' => $conflictingSchedule->id,
            'conflict_type' => 'room',
            'room_id' => $room->id,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $this->assertTrue($conflict->academicTerm->is($term));
        $this->assertTrue($conflict->classSchedule->is($schedule));
        $this->assertTrue($conflict->conflictingClassSchedule->is($conflictingSchedule));
        $this->assertTrue($conflict->room->is($room));
        $this->assertTrue($room->scheduleConflicts->contains($conflict));
        $this->assertTrue($term->scheduleConflicts->contains($conflict));
    }
}

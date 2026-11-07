<?php

namespace Tests\Feature\Database;

use App\Models\ClassSchedule;
use App\Models\RoomAllocation;
use App\Models\ScheduleConflict;
use App\Models\ScheduleAssignment;
use App\Models\StudentSubjectEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class ForeignKeyConstraintTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_deleting_a_role_with_users_is_restricted(): void
    {
        $role = $this->makeRole('student');
        $this->makeUser('student');

        $this->assertQueryFails(fn () => $role->delete());
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_deleting_an_offering_with_enrollments_is_restricted(): void
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

        $this->assertQueryFails(fn () => $offering->delete());
        $this->assertDatabaseHas('subject_offerings', ['id' => $offering->id]);
    }

    public function test_deleting_a_class_schedule_cascades_assignments_and_allocations(): void
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

        $schedule->delete();

        $this->assertDatabaseMissing('schedule_assignments', ['id' => $scheduleAssignment->id]);
        $this->assertDatabaseMissing('room_allocations', ['id' => $allocation->id]);
    }

    public function test_deleting_a_class_schedule_with_conflict_history_is_restricted(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $firstSchedule = $this->makeClassSchedule($offering);
        $secondSchedule = $this->makeClassSchedule($offering, [
            'day_of_week' => 2,
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);
        $room = $this->makeRoom();

        $conflict = ScheduleConflict::create([
            'academic_term_id' => $term->id,
            'class_schedule_id' => $firstSchedule->id,
            'conflicting_class_schedule_id' => $secondSchedule->id,
            'conflict_type' => 'room',
            'room_id' => $room->id,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $this->assertQueryFails(fn () => $firstSchedule->delete());
        $this->assertDatabaseHas('class_schedules', ['id' => $firstSchedule->id]);
        $this->assertDatabaseHas('schedule_conflicts', ['id' => $conflict->id]);
    }

    public function test_deleting_a_user_sets_actor_references_to_null(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $actor = $this->makeUser('administrator');
        $professor = $this->makeProfessor($this->makeUser('professor'), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering, [
            'assigned_by_user_id' => $actor->id,
        ]);

        $actor->delete();

        $this->assertDatabaseHas('faculty_assignments', [
            'id' => $facultyAssignment->id,
            'assigned_by_user_id' => null,
        ]);
    }
}

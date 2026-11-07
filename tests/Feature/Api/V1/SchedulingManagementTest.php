<?php

namespace Tests\Feature\Api\V1;

use App\Models\FacultyAvailability;
use App\Models\Role;
use App\Models\RoomAllocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleConflict;
use App\Models\StudentSubjectEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class SchedulingManagementTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_manager_can_create_assign_and_publish_a_valid_schedule(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $room = $this->makeRoom();
        $manager = $this->makeUser(Role::ADMINISTRATOR);
        $this->actingAs($manager);

        $scheduleResponse = $this->postJson('/api/v1/schedules', [
            'subject_offering_id' => $offering->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'schedule_type' => 'lecture',
            'modality' => 'face_to_face',
        ])->assertCreated();
        $scheduleId = $scheduleResponse->json('data.schedule.id');

        $facultyResponse = $this->postJson('/api/v1/faculty-assignments', [
            'professor_id' => $professor->id,
            'subject_offering_id' => $offering->id,
            'assignment_role' => 'lead',
        ])->assertCreated();

        $this->postJson("/api/v1/schedules/{$scheduleId}/assignments", [
            'faculty_assignment_id' => $facultyResponse->json('data.assignment.id'),
            'is_primary' => true,
        ])->assertCreated();
        $this->postJson("/api/v1/schedules/{$scheduleId}/room-allocations", [
            'room_id' => $room->id,
        ])->assertCreated();

        $this->postJson("/api/v1/schedules/{$scheduleId}/publish")
            ->assertOk()
            ->assertJsonPath('data.schedule.status', 'published');

        $this->assertDatabaseHas('class_schedules', ['id' => $scheduleId, 'status' => 'published']);
        $this->assertDatabaseHas('schedule_assignments', [
            'class_schedule_id' => $scheduleId,
            'status' => 'active',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('room_allocations', [
            'class_schedule_id' => $scheduleId,
            'room_id' => $room->id,
            'status' => 'active',
        ]);
    }

    public function test_schedule_listing_is_paginated_and_scoped_to_the_selected_term(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject);
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $firstSchedule = null;
        for ($index = 0; $index < 11; $index++) {
            $schedule = $this->makeClassSchedule($offering, [
                'status' => 'draft',
                'start_time' => sprintf('%02d:00:00', 7 + $index),
                'end_time' => sprintf('%02d:00:00', 8 + $index),
            ]);
            $firstSchedule ??= $schedule;
        }
        ScheduleAssignment::create([
            'class_schedule_id' => $firstSchedule->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        RoomAllocation::create([
            'class_schedule_id' => $firstSchedule->id,
            'room_id' => $this->makeRoom()->id,
            'status' => 'active',
            'allocated_at' => now(),
        ]);

        $this->actingAs($this->makeUser(Role::DEPARTMENT_PERSONNEL))
            ->getJson('/api/v1/schedules?academic_term_id='.$term->id.'&per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 11)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonCount(1, 'data.schedules');

        $this->getJson('/api/v1/schedules?'.http_build_query([
            'academic_term_id' => $term->id,
            'search' => $subject->code,
            'status' => 'draft',
            'program_id' => $program->id,
            'subject_id' => $subject->id,
            'section_id' => $section->id,
            'professor_id' => $professor->id,
            'room_id' => $firstSchedule->roomAllocations()->value('room_id'),
            'day_of_week' => 1,
            'per_page' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.schedules.0.status', 'draft');
    }

    public function test_active_administrators_and_department_personnel_can_access_scheduling(): void
    {
        $term = $this->makeAcademicTerm();

        foreach ([Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL] as $role) {
            $this->actingAs($this->makeUser($role));

            $this->getJson('/api/v1/schedules?academic_term_id='.$term->id)
                ->assertOk();

            $this->getJson('/api/v1/scheduling/options?academic_term_id='.$term->id)
                ->assertOk();
        }
    }

    public function test_deactivated_administrators_and_department_personnel_are_denied_scheduling_access(): void
    {
        $term = $this->makeAcademicTerm();

        foreach ([Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL] as $role) {
            $this->actingAs($this->makeUser($role, ['status' => 'inactive']));

            $this->getJson('/api/v1/schedules?academic_term_id='.$term->id)
                ->assertForbidden()
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', 'You do not have permission to access this resource.');

            $this->getJson('/api/v1/scheduling/options?academic_term_id='.$term->id)
                ->assertForbidden();
        }
    }

    public function test_duplicate_schedule_blocks_are_rejected(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $payload = [
            'subject_offering_id' => $offering->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'schedule_type' => 'lecture',
            'modality' => 'online',
        ];

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->postJson('/api/v1/schedules', $payload)
            ->assertCreated();
        $this->postJson('/api/v1/schedules', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_time');
    }

    public function test_weekday_must_occur_inside_the_offering_term(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $term->update(['starts_on' => '2026-08-03', 'ends_on' => '2026-08-03']);
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->postJson('/api/v1/schedules', [
                'subject_offering_id' => $offering->id,
                'day_of_week' => 2,
                'start_time' => '08:00',
                'end_time' => '09:00',
                'schedule_type' => 'lecture',
                'modality' => 'online',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_of_week');
    }

    public function test_professors_and_students_cannot_manage_schedules_or_conflicts(): void
    {
        $professor = $this->makeUser(Role::PROFESSOR);
        $student = $this->makeUser(Role::STUDENT);

        $this->actingAs($professor)
            ->postJson('/api/v1/schedules', [])
            ->assertForbidden();
        $this->getJson('/api/v1/schedule-conflicts')
            ->assertForbidden();
        $this->actingAs($student)
            ->postJson('/api/v1/faculty-assignments', [])
            ->assertForbidden();
        $this->getJson('/api/v1/schedule-conflicts')
            ->assertForbidden();
    }

    public function test_publishing_persists_section_conflicts_and_leaves_schedule_as_draft(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $firstOffering = $this->makeOffering($term, $section, $this->makeSubject());
        $secondOffering = $this->makeOffering($term, $section, $this->makeSubject());
        $published = $this->makeClassSchedule($firstOffering, ['status' => 'published', 'modality' => 'online']);
        $draft = $this->makeClassSchedule($secondOffering, ['status' => 'draft', 'modality' => 'online']);
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $secondOffering);
        ScheduleAssignment::create([
            'class_schedule_id' => $draft->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $admin = $this->makeUser(Role::ADMINISTRATOR);

        $this->actingAs($admin)
            ->postJson("/api/v1/schedules/{$draft->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('class_schedules', ['id' => $draft->id, 'status' => 'draft']);
        $this->assertDatabaseHas('schedule_conflicts', [
            'class_schedule_id' => min($published->id, $draft->id),
            'conflicting_class_schedule_id' => max($published->id, $draft->id),
            'conflict_type' => 'section',
            'section_id' => $section->id,
            'status' => 'open',
        ]);
    }

    public function test_recorded_conflict_can_be_waived_with_notes_by_department_personnel(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $firstOffering = $this->makeOffering($term, $section, $this->makeSubject());
        $secondOffering = $this->makeOffering($term, $section, $this->makeSubject());
        $this->makeClassSchedule($firstOffering, ['status' => 'published', 'modality' => 'online']);
        $draft = $this->makeClassSchedule($secondOffering, ['status' => 'draft', 'modality' => 'online']);
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $secondOffering);
        ScheduleAssignment::create([
            'class_schedule_id' => $draft->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $personnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);

        $this->actingAs($personnel)->postJson("/api/v1/schedules/{$draft->id}/publish")->assertUnprocessable();
        $conflict = ScheduleConflict::query()->firstOrFail();
        $this->postJson("/api/v1/schedule-conflicts/{$conflict->id}/waive", [
            'resolution_notes' => 'Approved room and section exception.',
        ])->assertOk()->assertJsonPath('data.conflict.status', 'waived');

        $this->postJson("/api/v1/schedules/{$draft->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.schedule.status', 'published');
    }

    public function test_published_schedule_requires_room_for_hybrid_and_capacity_for_allocated_rooms(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject(), ['expected_students' => 45]);
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $schedule = $this->makeClassSchedule($offering, ['status' => 'draft', 'modality' => 'hybrid']);
        ScheduleAssignment::create([
            'class_schedule_id' => $schedule->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $admin = $this->makeUser(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        $this->postJson("/api/v1/schedules/{$schedule->id}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_allocations');

        $smallRoom = $this->makeRoom(['capacity' => 30]);
        $this->postJson("/api/v1/schedules/{$schedule->id}/room-allocations", [
            'room_id' => $smallRoom->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->assertDatabaseMissing('room_allocations', [
            'class_schedule_id' => $schedule->id,
            'room_id' => $smallRoom->id,
        ]);
    }

    public function test_faculty_overlap_is_rejected_and_conflict_is_recorded(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $room = $this->makeRoom();
        $sections = [
            $this->makeSection($program, $term),
            $this->makeSection($program, $term),
        ];
        $offerings = array_map(fn ($section) => $this->makeOffering($term, $section, $this->makeSubject()), $sections);
        $facultyAssignments = array_map(
            fn ($offering) => $this->makeFacultyAssignment($professor, $offering),
            $offerings,
        );
        $published = $this->makeClassSchedule($offerings[0], ['status' => 'published']);
        ScheduleAssignment::create([
            'class_schedule_id' => $published->id,
            'faculty_assignment_id' => $facultyAssignments[0]->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        RoomAllocation::create([
            'class_schedule_id' => $published->id,
            'room_id' => $room->id,
            'status' => 'active',
            'allocated_at' => now(),
        ]);
        $draft = $this->makeClassSchedule($offerings[1], ['status' => 'draft']);
        ScheduleAssignment::create([
            'class_schedule_id' => $draft->id,
            'faculty_assignment_id' => $facultyAssignments[1]->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        RoomAllocation::create([
            'class_schedule_id' => $draft->id,
            'room_id' => $room->id,
            'status' => 'active',
            'allocated_at' => now(),
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->postJson("/api/v1/schedules/{$draft->id}/publish")
            ->assertUnprocessable();

        $this->assertDatabaseHas('schedule_conflicts', [
            'class_schedule_id' => min($published->id, $draft->id),
            'conflicting_class_schedule_id' => max($published->id, $draft->id),
            'conflict_type' => 'faculty',
            'professor_id' => $professor->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('schedule_conflicts', [
            'class_schedule_id' => min($published->id, $draft->id),
            'conflicting_class_schedule_id' => max($published->id, $draft->id),
            'conflict_type' => 'room',
            'room_id' => $room->id,
            'status' => 'open',
        ]);
    }

    public function test_schedules_touching_at_an_endpoint_do_not_conflict(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $firstOffering = $this->makeOffering($term, $section, $this->makeSubject());
        $secondOffering = $this->makeOffering($term, $section, $this->makeSubject());
        $this->makeClassSchedule($firstOffering, [
            'status' => 'published',
            'modality' => 'online',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);
        $draft = $this->makeClassSchedule($secondOffering, [
            'status' => 'draft',
            'modality' => 'online',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
        ]);
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $secondOffering);
        ScheduleAssignment::create([
            'class_schedule_id' => $draft->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->postJson("/api/v1/schedules/{$draft->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.schedule.status', 'published');
        $this->assertDatabaseMissing('schedule_conflicts', [
            'conflict_type' => 'section',
            'section_id' => $section->id,
        ]);
    }

    public function test_professor_and_student_only_receive_their_own_published_schedules(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $schedule = $this->makeClassSchedule($offering, ['status' => 'published']);
        ScheduleAssignment::create([
            'class_schedule_id' => $schedule->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $student = $this->makeStudent($this->makeUser(Role::STUDENT), $program);
        StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);
        $otherOffering = $this->makeOffering($term, $this->makeSection($program, $term), $this->makeSubject());
        $otherSchedule = $this->makeClassSchedule($otherOffering, ['status' => 'published']);

        $this->actingAs($professor->user)
            ->getJson('/api/v1/schedules/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', $schedule->id);
        $this->actingAs($student->user)
            ->getJson('/api/v1/schedules/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.schedules')
            ->assertJsonPath('data.schedules.0.id', $schedule->id);
        $this->actingAs($student->user)
            ->getJson("/api/v1/schedules/{$otherSchedule->id}")
            ->assertForbidden();
        $this->actingAs($professor->user)
            ->getJson("/api/v1/schedules/{$otherSchedule->id}")
            ->assertForbidden();
    }

    public function test_inactive_or_unavailable_professor_cannot_be_published(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $schedule = $this->makeClassSchedule($offering, ['status' => 'draft']);
        ScheduleAssignment::create([
            'class_schedule_id' => $schedule->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        FacultyAvailability::create([
            'professor_id' => $professor->id,
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '08:30:00',
            'end_time' => '09:30:00',
            'availability_type' => 'unavailable',
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->postJson("/api/v1/schedules/{$schedule->id}/publish")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_time');
        $this->assertDatabaseHas('class_schedules', ['id' => $schedule->id, 'status' => 'draft']);
    }

    public function test_professor_account_cannot_be_deactivated_while_assigned_to_a_published_schedule(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professorUser = $this->makeUser(Role::PROFESSOR);
        $professor = $this->makeProfessor($professorUser, $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $schedule = $this->makeClassSchedule($offering, ['status' => 'published']);
        ScheduleAssignment::create([
            'class_schedule_id' => $schedule->id,
            'faculty_assignment_id' => $facultyAssignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        $admin = $this->makeUser(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        $this->patchJson("/api/v1/users/{$professorUser->id}/status", ['status' => 'inactive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('users', ['id' => $professorUser->id, 'status' => 'active']);

        $this->postJson("/api/v1/schedules/{$schedule->id}/cancel")->assertOk();
        $this->patchJson("/api/v1/users/{$professorUser->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'inactive');
    }

    public function test_cancel_keeps_schedule_and_related_history_records(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $schedule = $this->makeClassSchedule($offering, ['status' => 'published']);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->postJson("/api/v1/schedules/{$schedule->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.schedule.status', 'cancelled');

        $this->assertDatabaseHas('class_schedules', ['id' => $schedule->id, 'status' => 'cancelled']);
    }
}

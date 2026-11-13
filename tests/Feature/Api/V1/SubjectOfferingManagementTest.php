<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\Role;
use App\Models\RoomAllocation;
use App\Models\StudentSubjectEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class SubjectOfferingManagementTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_administrators_and_department_personnel_can_manage_offerings(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();

        foreach ([Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL] as $role) {
            $this->actingAs($this->makeUser($role));

            $this->getJson('/api/v1/academic/subject-offerings/options')
                ->assertOk()
                ->assertJsonStructure(['data' => ['terms', 'programs', 'sections', 'subjects']]);

            $this->getJson('/api/v1/academic/subject-offerings')
                ->assertOk()
                ->assertJsonStructure(['data' => ['offerings', 'pagination']]);
        }

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));
        $created = $this->postJson('/api/v1/academic/subject-offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'expected_students' => 45,
            'status' => 'open',
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject_offering.status', 'draft')
            ->assertJsonPath('data.subject_offering.expected_students', 45);

        $offeringId = $created->json('data.subject_offering.id');

        $this->getJson("/api/v1/academic/subject-offerings/{$offeringId}")
            ->assertOk()
            ->assertJsonPath('data.subject_offering.id', $offeringId)
            ->assertJsonPath('data.subject_offering.enrolled_students_count', 0);
    }

    public function test_professors_students_and_inactive_managers_cannot_access_offering_management(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());

        foreach ([Role::PROFESSOR, Role::STUDENT] as $role) {
            $this->actingAs($this->makeUser($role));

            $this->getJson('/api/v1/academic/subject-offerings')->assertForbidden();
            $this->getJson('/api/v1/academic/subject-offerings/options')->assertForbidden();
            $this->getJson("/api/v1/academic/subject-offerings/{$offering->id}")->assertForbidden();
            $this->postJson('/api/v1/academic/subject-offerings', [])->assertForbidden();
            $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [])->assertForbidden();
            $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
                'status' => 'closed',
            ])->assertForbidden();
        }

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR, ['status' => 'inactive']))
            ->getJson('/api/v1/academic/subject-offerings')
            ->assertForbidden();
    }

    public function test_offering_directory_is_searchable_filterable_and_paginated(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $section = $this->makeSection($program, $term, null, ['code' => 'SEC-ALPHA']);
        $first = $this->makeOffering($term, $section, $this->makeSubject([
            'code' => 'IT101',
            'title' => 'Introduction to Computing',
        ]), ['status' => 'draft']);

        $otherProgram = $this->makeProgram();
        $otherSection = $this->makeSection($otherProgram, $term, null, ['code' => 'SEC-BETA']);
        $second = $this->makeOffering($term, $otherSection, $this->makeSubject([
            'code' => 'IT202',
            'title' => 'Data Structures',
        ]), ['status' => 'open']);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->getJson('/api/v1/academic/subject-offerings?search=Computing&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.offerings.0.id', $first->id);

        $this->getJson('/api/v1/academic/subject-offerings?search=SEC-BETA')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.offerings.0.id', $second->id);

        $this->getJson('/api/v1/academic/subject-offerings?status=open&program_id='.$otherProgram->id)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.offerings.0.id', $second->id);

        $this->getJson('/api/v1/academic/subject-offerings?subject_id='.$first->subject_id)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.offerings.0.id', $first->id);
    }

    public function test_offering_creation_starts_as_draft_and_enforces_term_and_duplicate_rules(): void
    {
        $term = $this->makeAcademicTerm();
        $otherTerm = $this->makeAcademicTerm(false);
        $program = $this->makeProgram();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();

        $archivedTerm = AcademicTerm::create([
            'academic_year' => '2025-2026',
            'term' => 'first',
            'name' => 'Archived Semester',
            'starts_on' => '2025-08-01',
            'ends_on' => '2025-12-19',
            'is_current' => false,
            'status' => 'archived',
        ]);
        $archivedSection = $this->makeSection($program, $archivedTerm);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $created = $this->postJson('/api/v1/academic/subject-offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'expected_students' => 30,
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject_offering.status', 'draft');

        $this->assertDatabaseHas('subject_offerings', [
            'id' => $created->json('data.subject_offering.id'),
            'status' => 'draft',
        ]);

        $this->postJson('/api/v1/academic/subject-offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'expected_students' => 30,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');

        $this->postJson('/api/v1/academic/subject-offerings', [
            'academic_term_id' => $otherTerm->id,
            'section_id' => $section->id,
            'subject_id' => $this->makeSubject()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_term_id');

        $this->postJson('/api/v1/academic/subject-offerings', [
            'academic_term_id' => $archivedTerm->id,
            'section_id' => $archivedSection->id,
            'subject_id' => $this->makeSubject()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_term_id');

        $this->postJson('/api/v1/academic/subject-offerings', [
            'academic_term_id' => $term->id,
            'section_id' => $section->id,
            'subject_id' => $this->makeSubject()->id,
            'expected_students' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_students');
    }

    public function test_draft_offerings_are_editable_and_open_offerings_lock_structure(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $replacementSubject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject, ['status' => 'draft']);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'subject_id' => $replacementSubject->id,
            'expected_students' => 35,
        ])
            ->assertOk()
            ->assertJsonPath('data.subject_offering.subject.id', $replacementSubject->id)
            ->assertJsonPath('data.subject_offering.expected_students', 35);

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'open',
        ])->assertOk()->assertJsonPath('data.subject_offering.status', 'open');

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'subject_id' => $subject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'expected_students' => 50,
        ])->assertOk()->assertJsonPath('data.subject_offering.expected_students', 50);

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'closed',
        ])->assertOk()->assertJsonPath('data.subject_offering.status', 'closed');

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'expected_students' => 20,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'open',
        ])->assertOk()->assertJsonPath('data.subject_offering.status', 'open');
    }

    public function test_opening_revalidates_related_statuses_and_allows_valid_reopening(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $section = $this->makeSection($program, $term);
        $subject = $this->makeSubject();
        $offering = $this->makeOffering($term, $section, $subject, ['status' => 'draft']);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'closed',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'open',
        ])->assertOk();

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'closed',
        ])->assertOk();

        $subject->update(['status' => 'inactive']);

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'open',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $subject->update(['status' => 'active']);

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}/status", [
            'status' => 'open',
        ])->assertOk()->assertJsonPath('data.subject_offering.status', 'open');
    }

    public function test_expected_students_guard_enrollment_and_published_room_capacity(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject(), [
            'status' => 'open',
            'expected_students' => 40,
        ]);

        for ($index = 1; $index <= 10; $index++) {
            $student = $this->makeStudent($this->makeUser(Role::STUDENT), $program);
            StudentSubjectEnrollment::create([
                'student_id' => $student->id,
                'subject_offering_id' => $offering->id,
                'status' => 'enrolled',
            ]);
        }

        $room = $this->makeRoom(['capacity' => 20]);
        $schedule = $this->makeClassSchedule($offering, ['status' => 'published']);
        RoomAllocation::create([
            'class_schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'expected_students' => 9,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_students');

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'expected_students' => 10,
        ])->assertOk();

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'expected_students' => 21,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_students');

        $this->patchJson("/api/v1/academic/subject-offerings/{$offering->id}", [
            'expected_students' => 20,
        ])->assertOk()->assertJsonPath('data.subject_offering.expected_students', 20);

        $this->assertDatabaseHas('room_allocations', [
            'class_schedule_id' => $schedule->id,
            'status' => 'active',
        ]);
    }

    public function test_offering_details_include_counts_and_active_schedule_context(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $this->makeFacultyAssignment($professor, $offering);

        $student = $this->makeStudent($this->makeUser(Role::STUDENT), $program);
        StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
        ]);

        $room = $this->makeRoom();
        $schedule = $this->makeClassSchedule($offering);
        RoomAllocation::create([
            'class_schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR))
            ->getJson("/api/v1/academic/subject-offerings/{$offering->id}")
            ->assertOk()
            ->assertJsonPath('data.subject_offering.enrolled_students_count', 1)
            ->assertJsonPath('data.subject_offering.active_faculty_assignments_count', 1)
            ->assertJsonPath('data.subject_offering.published_schedules_count', 1)
            ->assertJsonCount(1, 'data.subject_offering.faculty_assignments')
            ->assertJsonCount(1, 'data.subject_offering.class_schedules')
            ->assertJsonPath('data.subject_offering.class_schedules.0.room_allocations.0.room.id', $room->id);
    }
}

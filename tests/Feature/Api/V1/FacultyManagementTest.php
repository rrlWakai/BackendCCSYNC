<?php

namespace Tests\Feature\Api\V1;

use App\Models\FacultyAvailability;
use App\Models\Professor;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class FacultyManagementTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_faculty_directory_searches_filters_and_paginates_professor_accounts(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $program = $this->makeProgram();

        $firstUser = $this->makeUser(Role::PROFESSOR, [
            'name' => 'Avery Professor',
            'email' => 'avery-prof@example.test',
        ]);
        $firstProfessor = $this->makeProfessor($firstUser, $program);
        $firstProfessor->update(['employee_number' => 'EMP-FACULTY-001']);

        $secondUser = $this->makeUser(Role::PROFESSOR, [
            'name' => 'Morgan Professor',
            'email' => 'morgan-prof@example.test',
            'status' => 'inactive',
        ]);
        $secondProfessor = $this->makeProfessor($secondUser, $program);
        $secondProfessor->update(['employee_number' => 'EMP-FACULTY-002']);
        $this->makeUser(Role::STUDENT, ['name' => 'Not faculty']);

        $this->getJson('/api/v1/faculty?search=EMP-FACULTY-001&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.faculty.0.employee_number', 'EMP-FACULTY-001')
            ->assertJsonPath('data.faculty.0.email', 'avery-prof@example.test');

        $this->getJson('/api/v1/faculty?search=morgan-prof%40example.test&status=inactive&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.faculty.0.id', $secondProfessor->id);

        $this->getJson('/api/v1/faculty?search=Avery&status=inactive')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        for ($index = 0; $index < 10; $index++) {
            $user = $this->makeUser(Role::PROFESSOR, [
                'name' => sprintf('Faculty %02d', $index),
            ]);
            $this->makeProfessor($user, $program);
        }

        $this->getJson('/api/v1/faculty?per_page=10&page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.faculty.0.name', 'Avery Professor');
        $this->getJson('/api/v1/faculty?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2);
    }

    public function test_managers_and_professors_can_read_only_authorized_faculty_profiles_and_assignments(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professorUser = $this->makeUser(Role::PROFESSOR, [
            'name' => 'Own Professor',
            'email' => 'own-professor@example.test',
        ]);
        $professor = $this->makeProfessor($professorUser, $program);
        $professor->update([
            'employee_number' => 'EMP-OWN-001',
            'specialization' => 'Software engineering',
        ]);
        $assignment = $this->makeFacultyAssignment($professor, $offering, [
            'assigned_units' => 3.0,
        ]);
        $this->createAvailability($professor, $term->id);

        $otherUser = $this->makeUser(Role::PROFESSOR, [
            'email' => 'other-professor@example.test',
        ]);
        $otherProfessor = $this->makeProfessor($otherUser, $program);

        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->getJson('/api/v1/faculty/'.$professor->id)
            ->assertOk()
            ->assertJsonPath('data.professor.employee_number', 'EMP-OWN-001')
            ->assertJsonPath('data.professor.account_status', 'active')
            ->assertJsonPath('data.professor.program.id', $program->id)
            ->assertJsonPath('data.professor.availability.0.academic_term.id', $term->id)
            ->assertJsonPath('data.professor.assignments.0.id', $assignment->id)
            ->assertJsonPath('data.professor.assignments.0.offering.subject.code', $offering->subject->code)
            ->assertJsonPath('data.professor.assignments.0.offering.section.code', $section->code)
            ->assertJsonPath('data.professor.assignments.0.offering.academic_term.id', $term->id);
        $this->getJson('/api/v1/faculty/'.$professor->id.'/assignments')
            ->assertOk()
            ->assertJsonPath('data.assignments.0.id', $assignment->id);

        Sanctum::actingAs($professorUser);
        $this->getJson('/api/v1/faculty/me')
            ->assertOk()
            ->assertJsonPath('data.professor.user_id', $professorUser->id);
        $this->getJson('/api/v1/faculty/me/assignments')
            ->assertOk()
            ->assertJsonPath('data.assignments.0.id', $assignment->id);
        $this->getJson('/api/v1/faculty/'.$otherProfessor->id)->assertForbidden();
        $this->getJson('/api/v1/faculty')->assertForbidden();

        Sanctum::actingAs($this->makeUser(Role::STUDENT));
        $this->getJson('/api/v1/faculty/'.$professor->id)->assertForbidden();
        $this->getJson('/api/v1/faculty')->assertForbidden();
        $this->getJson('/api/v1/faculty/me')->assertForbidden();
    }

    public function test_department_personnel_can_read_faculty_and_existing_user_workflow_edits_profile(): void
    {
        $program = $this->makeProgram();
        $user = $this->makeUser(Role::PROFESSOR);
        $professor = $this->makeProfessor($user, $program);
        $newProgram = $this->makeProgram();

        Sanctum::actingAs($this->makeUser(Role::DEPARTMENT_PERSONNEL));
        $this->getJson('/api/v1/faculty')->assertOk();
        $this->patchJson('/api/v1/users/'.$user->id, [
            'name' => 'Updated Professor',
            'profile' => [
                'employee_number' => 'EMP-UPDATED-001',
                'program_id' => $newProgram->id,
                'employment_type' => 'part_time',
                'specialization' => 'Data science',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Updated Professor')
            ->assertJsonPath('data.user.profile.employee_number', 'EMP-UPDATED-001');

        $this->assertSame($newProgram->id, $professor->fresh()->program_id);
        $this->assertSame('part_time', $professor->fresh()->employment_type);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Professor']);
    }

    public function test_professor_can_update_only_own_permitted_profile_fields_and_employee_numbers_are_unique(): void
    {
        $program = $this->makeProgram();
        $firstUser = $this->makeUser(Role::PROFESSOR);
        $firstProfessor = $this->makeProfessor($firstUser, $program);
        $secondUser = $this->makeUser(Role::PROFESSOR);
        $secondProfessor = $this->makeProfessor($secondUser, $program);

        Sanctum::actingAs($firstUser);
        $this->patchJson('/api/v1/faculty/me', [
            'employee_number' => $firstProfessor->employee_number,
            'employment_type' => 'visiting',
            'specialization' => 'Artificial intelligence',
        ])
            ->assertOk()
            ->assertJsonPath('data.professor.employment_type', 'visiting')
            ->assertJsonPath('data.professor.specialization', 'Artificial intelligence');

        $this->patchJson('/api/v1/faculty/me', [
            'employee_number' => $secondProfessor->employee_number,
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['employee_number']]);

        $this->patchJson('/api/v1/faculty/me', [
            'name' => 'Attempt account identity change',
            'status' => 'inactive',
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['name', 'status']]);

        $this->assertSame($firstUser->name, $firstUser->fresh()->name);
        $this->assertSame('active', $firstUser->fresh()->status);
    }

    public function test_faculty_availability_crud_validates_windows_and_blocks_overlaps(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $professorUser = $this->makeUser(Role::PROFESSOR);
        $professor = $this->makeProfessor($professorUser, $program);
        $admin = $this->makeUser(Role::ADMINISTRATOR);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/faculty/'.$professor->id.'/availability', [
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'availability_type' => 'available',
            'notes' => 'Morning window',
        ])
            ->assertCreated()
            ->assertJsonPath('data.availability.day_of_week', 1)
            ->assertJsonPath('data.availability.start_time', '09:00');
        $availabilityId = $created->json('data.availability.id');

        $this->postJson('/api/v1/faculty/'.$professor->id.'/availability', [
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '10:30',
            'end_time' => '12:00',
            'availability_type' => 'preferred',
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['start_time']]);

        $this->postJson('/api/v1/faculty/'.$professor->id.'/availability', [
            'academic_term_id' => $term->id,
            'day_of_week' => 8,
            'start_time' => '14:00',
            'end_time' => '13:00',
            'availability_type' => 'unsupported',
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => [
                'day_of_week',
                'end_time',
                'availability_type',
            ]]);

        $this->patchJson('/api/v1/faculty/availability/'.$availabilityId, [
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '09:30',
            'end_time' => '11:30',
            'availability_type' => 'preferred',
        ])
            ->assertOk()
            ->assertJsonPath('data.availability.availability_type', 'preferred');

        Sanctum::actingAs($professorUser);
        $this->getJson('/api/v1/faculty/me/availability')
            ->assertOk()
            ->assertJsonPath('data.availability.0.id', $availabilityId);
        $this->deleteJson('/api/v1/faculty/availability/'.$availabilityId)
            ->assertOk();
        $this->assertDatabaseMissing('faculty_availability', ['id' => $availabilityId]);
    }

    public function test_availability_changes_cannot_invalidate_published_teaching_schedules(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professorUser = $this->makeUser(Role::PROFESSOR);
        $professor = $this->makeProfessor($professorUser, $program);
        $assignment = $this->makeFacultyAssignment($professor, $offering);
        $schedule = $this->makeClassSchedule($offering, [
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'published',
        ]);
        $schedule->scheduleAssignments()->create([
            'faculty_assignment_id' => $assignment->id,
            'is_primary' => true,
            'status' => 'active',
        ]);
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));

        $created = $this->postJson('/api/v1/faculty/'.$professor->id.'/availability', [
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '11:00',
            'availability_type' => 'available',
        ])->assertCreated();
        $id = $created->json('data.availability.id');

        $this->patchJson('/api/v1/faculty/availability/'.$id, [
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'availability_type' => 'available',
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['availability_type']]);
        $this->deleteJson('/api/v1/faculty/availability/'.$id)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['availability_type']]);
        $this->assertDatabaseHas('faculty_availability', [
            'id' => $id,
            'start_time' => '08:00:00',
        ]);
    }

    public function test_professor_availability_and_detail_are_scoped_to_owner_and_students_are_denied(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $firstUser = $this->makeUser(Role::PROFESSOR);
        $firstProfessor = $this->makeProfessor($firstUser, $program);
        $availability = $this->createAvailability($firstProfessor, $term->id);
        $otherProfessorUser = $this->makeUser(Role::PROFESSOR);
        $otherProfessor = $this->makeProfessor($otherProfessorUser, $program);

        Sanctum::actingAs($firstUser);
        $this->getJson('/api/v1/faculty/'.$firstProfessor->id.'/availability')->assertOk();
        $this->postJson('/api/v1/faculty/'.$firstProfessor->id.'/availability', [
            'academic_term_id' => $term->id,
            'day_of_week' => 2,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'availability_type' => 'available',
        ])->assertCreated();
        $this->patchJson('/api/v1/faculty/availability/'.$availability->id, [
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'availability_type' => 'available',
        ])->assertOk();

        Sanctum::actingAs($otherProfessorUser);
        $this->getJson('/api/v1/faculty/'.$firstProfessor->id)->assertForbidden();
        $this->getJson('/api/v1/faculty/'.$firstProfessor->id.'/availability')->assertForbidden();
        $this->patchJson('/api/v1/faculty/availability/'.$availability->id, [
            'academic_term_id' => $term->id,
            'day_of_week' => 1,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'availability_type' => 'available',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/faculty/availability/'.$availability->id)->assertForbidden();
        $this->getJson('/api/v1/faculty/'.$otherProfessor->id)->assertOk();

        Sanctum::actingAs($this->makeUser(Role::STUDENT));
        $this->getJson('/api/v1/faculty/'.$firstProfessor->id)->assertForbidden();
        $this->getJson('/api/v1/faculty/'.$firstProfessor->id.'/availability')->assertForbidden();
        $this->postJson('/api/v1/faculty/'.$firstProfessor->id.'/availability', [])->assertForbidden();
    }

    public function test_faculty_endpoints_require_authentication_and_validate_directory_filters(): void
    {
        $this->getJson('/api/v1/faculty')->assertUnauthorized();
        $this->getJson('/api/v1/faculty/me')->assertUnauthorized();
        $this->getJson('/api/v1/faculty/1')->assertUnauthorized();

        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->getJson('/api/v1/faculty?status=graduated')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['status']]);
        $this->getJson('/api/v1/faculty?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    private function createAvailability(Professor $professor, int $termId): FacultyAvailability
    {
        return $professor->facultyAvailability()->create([
            'academic_term_id' => $termId,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'availability_type' => 'available',
        ]);
    }
}

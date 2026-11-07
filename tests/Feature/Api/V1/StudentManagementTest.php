<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_student_directory_searches_filters_and_paginates_by_name(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        Sanctum::actingAs($administrator);
        $program = $this->makeProgram();
        $otherProgram = $this->makeProgram();

        $firstUser = $this->makeUser(Role::STUDENT, [
            'name' => 'Avery Student',
            'email' => 'avery@example.test',
        ]);
        $firstStudent = $this->makeStudent($firstUser, $program);
        $firstStudent->update(['student_number' => 'STU-SEARCH-001']);

        $secondUser = $this->makeUser(Role::STUDENT, [
            'name' => 'Morgan Student',
            'email' => 'morgan@example.test',
        ]);
        $secondStudent = $this->makeStudent($secondUser, $otherProgram);
        $secondStudent->update(['student_number' => 'STU-SEARCH-002']);
        $secondUser->update(['status' => 'inactive']);

        $this->makeUser(Role::PROFESSOR, [
            'name' => 'Not a student',
            'email' => 'other@example.test',
        ]);

        $this->getJson('/api/v1/students?search=STU-SEARCH-001&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.students.0.student_number', 'STU-SEARCH-001')
            ->assertJsonPath('data.students.0.email', 'avery@example.test');

        $this->getJson('/api/v1/students?search=Morgan&status=inactive&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.students.0.name', 'Morgan Student');

        $this->getJson('/api/v1/students?search=avery%40example.test&program_id='.$program->id.'&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.students.0.program.id', $program->id);

        $this->getJson('/api/v1/students?status=active&program_id='.$otherProgram->id.'&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        for ($index = 0; $index < 10; $index++) {
            $user = $this->makeUser(Role::STUDENT, [
                'name' => sprintf('Student %02d', $index),
            ]);
            $this->makeStudent($user, $program);
        }

        $this->getJson('/api/v1/students?per_page=10&page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.students.0.name', 'Avery Student');

        $this->getJson('/api/v1/students?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2);
    }

    public function test_existing_account_workflow_registers_student_and_rejects_duplicate_identity(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->makeRole(Role::STUDENT);
        $program = $this->makeProgram();

        $response = $this->postJson('/api/v1/users', [
            'name' => 'Registered Student',
            'email' => 'REGISTERED@example.test',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
            'role' => Role::STUDENT,
            'profile' => [
                'student_number' => 'STU-REGISTER-001',
                'program_id' => $program->id,
                'admitted_on' => '2026-08-03',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.profile.type', 'student')
            ->assertJsonPath('data.user.profile.student_number', 'STU-REGISTER-001');

        $userId = $response->json('data.user.id');
        $user = User::with('student')->findOrFail($userId);
        $this->assertSame('registered@example.test', $user->email);
        $this->assertSame('STU-REGISTER-001', $user->student->student_number);
        $this->assertTrue(Hash::check('a-strong-password-123', $user->password));
        $this->assertDatabaseHas('students', [
            'user_id' => $user->id,
            'program_id' => $program->id,
        ]);

        $this->postJson('/api/v1/users', [
            'name' => 'Duplicate Student',
            'email' => 'duplicate-number@example.test',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
            'role' => Role::STUDENT,
            'profile' => [
                'student_number' => 'STU-REGISTER-001',
                'program_id' => $program->id,
            ],
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['profile.student_number']]);

        $this->postJson('/api/v1/users', [
            'name' => 'Duplicate Email',
            'email' => 'registered@example.test',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
            'role' => Role::STUDENT,
            'profile' => [
                'student_number' => 'STU-REGISTER-002',
                'program_id' => $program->id,
            ],
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_student_profile_creation_rolls_back_the_user_if_profile_creation_fails(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->makeRole(Role::STUDENT);
        $program = $this->makeProgram();

        Student::creating(function (): void {
            throw new \RuntimeException('Profile creation failed.');
        });

        $this->postJson('/api/v1/users', [
            'name' => 'Rollback Student',
            'email' => 'rollback@example.test',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
            'role' => Role::STUDENT,
            'profile' => [
                'student_number' => 'STU-ROLLBACK-001',
                'program_id' => $program->id,
            ],
        ])->assertInternalServerError();

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseMissing('students', ['student_number' => 'STU-ROLLBACK-001']);
    }

    public function test_managers_can_view_edit_and_change_student_account_status(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $program = $this->makeProgram();
        $studentUser = $this->makeUser(Role::STUDENT, [
            'name' => 'Managed Student',
            'email' => 'managed@example.test',
        ]);
        $student = $this->makeStudent($studentUser, $program);
        $student->update([
            'student_number' => 'STU-MANAGED-001',
            'admitted_on' => '2026-08-03',
        ]);

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.student.email', 'managed@example.test')
            ->assertJsonPath('data.student.student_number', 'STU-MANAGED-001')
            ->assertJsonPath('data.student.program.code', $program->code)
            ->assertJsonPath('data.student.profile_status', 'active')
            ->assertJsonPath('data.student.admitted_on', '2026-08-03')
            ->assertJsonPath('data.student.enrollments', []);

        $this->patchJson('/api/v1/users/'.$studentUser->id, [
            'name' => 'Updated Student',
            'email' => 'updated@example.test',
            'profile' => [
                'student_number' => 'STU-MANAGED-002',
                'program_id' => $program->id,
                'admitted_on' => '2026-08-04',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Updated Student')
            ->assertJsonPath('data.user.profile.student_number', 'STU-MANAGED-002');

        $this->patchJson('/api/v1/users/'.$studentUser->id.'/status', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'inactive');

        $this->assertSame('active', $student->fresh()->status);
        $this->assertSame('inactive', $studentUser->fresh()->status);

        Sanctum::actingAs($this->makeUser(Role::DEPARTMENT_PERSONNEL));
        $this->getJson('/api/v1/students?search=Updated')->assertOk()
            ->assertJsonPath('data.students.0.student_number', 'STU-MANAGED-002');
        $this->getJson('/api/v1/students/'.$student->id)->assertOk();
        $this->patchJson('/api/v1/users/'.$studentUser->id, [
            'name' => 'Personnel Updated',
        ])->assertOk()->assertJsonPath('data.user.name', 'Personnel Updated');
        $this->patchJson('/api/v1/users/'.$studentUser->id.'/status', ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'active');
        $this->postJson('/api/v1/users', [])->assertForbidden();
    }

    public function test_student_and_professor_can_only_read_student_details_permitted_by_relationships(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $yearLevel = $this->makeYearLevel();

        $studentUser = $this->makeUser(Role::STUDENT, [
            'name' => 'Own Student',
            'email' => 'own-student@example.test',
        ]);
        $student = $this->makeStudent($studentUser, $program);
        $section = $this->makeSection($program, $term, $yearLevel);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $student->enrollments()->create([
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
        ]);

        $otherUser = $this->makeUser(Role::STUDENT, [
            'name' => 'Other Student',
            'email' => 'other-student@example.test',
        ]);
        $otherStudent = $this->makeStudent($otherUser, $program);

        Sanctum::actingAs($studentUser);
        $this->getJson('/api/v1/students/me')
            ->assertOk()
            ->assertJsonPath('data.student.id', $student->id)
            ->assertJsonPath('data.student.email', 'own-student@example.test')
            ->assertJsonPath('data.student.enrollments.0.subject_offering.subject.code', $offering->subject->code)
            ->assertJsonPath('data.student.enrollments.0.subject_offering.section.year_level.id', $yearLevel->id)
            ->assertJsonPath('data.student.enrollments.0.subject_offering.academic_term.id', $term->id);
        $this->getJson('/api/v1/students/'.$otherStudent->id)->assertForbidden();
        $this->getJson('/api/v1/students')->assertForbidden();
        $this->patchJson('/api/v1/users/'.$studentUser->id, ['name' => 'Not permitted'])
            ->assertForbidden();

        $professorUser = $this->makeUser(Role::PROFESSOR, ['name' => 'Assigned Professor']);
        $professor = $this->makeProfessor($professorUser, $program);
        $this->makeFacultyAssignment($professor, $offering);

        Sanctum::actingAs($professorUser);
        $this->getJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonMissingPath('data.student.email')
            ->assertJsonPath('data.student.name', 'Own Student')
            ->assertJsonCount(1, 'data.student.enrollments');
        $this->getJson('/api/v1/students/'.$otherStudent->id)->assertForbidden();
        $this->getJson('/api/v1/students')->assertForbidden();
        $this->getJson('/api/v1/students/me')->assertForbidden();
        $this->patchJson('/api/v1/users/'.$studentUser->id.'/status', ['status' => 'inactive'])
            ->assertForbidden();
    }

    public function test_student_routes_require_authentication_and_reject_invalid_directory_filters(): void
    {
        $this->getJson('/api/v1/students')->assertUnauthorized();
        $this->getJson('/api/v1/students/me')->assertUnauthorized();
        $this->getJson('/api/v1/students/1')->assertUnauthorized();

        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->getJson('/api/v1/students?status=graduated')
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['status']]);
        $this->getJson('/api/v1/students?program_id=999999')
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['program_id']]);
    }
}

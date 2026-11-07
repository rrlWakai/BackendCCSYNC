<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_administrator_can_create_student_and_professor_accounts_with_profiles(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->makeRole(Role::STUDENT);
        $this->makeRole(Role::PROFESSOR);
        $program = $this->makeProgram();

        $studentResponse = $this->postJson('/api/v1/users', [
            'name' => 'New Student',
            'email' => ' NEW.STUDENT@example.test ',
            'password' => 'a-strong-password-123',
            'password_confirmation' => 'a-strong-password-123',
            'role' => Role::STUDENT,
            'profile' => [
                'student_number' => 'STU-NEW-001',
                'program_id' => $program->id,
                'admitted_on' => '2026-08-03',
            ],
        ]);

        $studentResponse
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'new.student@example.test')
            ->assertJsonPath('data.user.role.code', Role::STUDENT)
            ->assertJsonPath('data.user.profile.type', 'student')
            ->assertJsonPath('data.user.profile.student_number', 'STU-NEW-001')
            ->assertJsonMissingPath('data.user.password');

        $student = User::where('email', 'new.student@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('a-strong-password-123', $student->password));
        $this->assertSame('active', $student->student->status);

        $this->postJson('/api/v1/users', [
            'name' => 'New Professor',
            'email' => 'new.professor@example.test',
            'password' => 'a-strong-password-456',
            'password_confirmation' => 'a-strong-password-456',
            'role' => Role::PROFESSOR,
            'profile' => [
                'employee_number' => 'EMP-NEW-001',
                'program_id' => $program->id,
                'employment_type' => 'full_time',
                'specialization' => 'Computer Science',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.role.code', Role::PROFESSOR)
            ->assertJsonPath('data.user.profile.type', 'professor')
            ->assertJsonPath('data.user.profile.employee_number', 'EMP-NEW-001')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_creation_validates_profile_fields_email_password_and_active_program(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $inactiveProgram = $this->makeProgram();
        $inactiveProgram->update(['status' => 'inactive']);

        $this->postJson('/api/v1/users', [
            'name' => 'Invalid Student',
            'email' => 'invalid-student@example.test',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
            'role' => Role::STUDENT,
            'profile' => [
                'student_number' => 'DUPLICATE',
                'program_id' => $inactiveProgram->id,
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['password', 'profile.program_id']]);
    }

    public function test_administrator_can_create_accounts_without_academic_profiles(): void
    {
        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->makeRole(Role::ADMINISTRATOR);
        $this->makeRole(Role::DEPARTMENT_PERSONNEL);

        foreach ([Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL] as $role) {
            $this->postJson('/api/v1/users', [
                'name' => ucfirst($role).' Account',
                'email' => $role.'-new@example.test',
                'password' => 'a-strong-password-123',
                'password_confirmation' => 'a-strong-password-123',
                'role' => $role,
            ])
                ->assertCreated()
                ->assertJsonPath('data.user.role.code', $role)
                ->assertJsonPath('data.user.profile', null);
        }
    }

    public function test_administrator_can_search_filter_paginate_update_and_activate_accounts(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        Sanctum::actingAs($administrator);
        $program = $this->makeProgram();

        $student = $this->makeUser(Role::STUDENT, [
            'name' => 'Searchable Student',
            'email' => 'searchable@example.test',
        ]);
        $this->makeStudent($student, $program);

        $inactive = $this->makeUser(Role::PROFESSOR, [
            'name' => 'Inactive Professor',
            'status' => 'inactive',
        ]);
        $this->makeProfessor($inactive, $program);

        $this->getJson('/api/v1/users?search=Searchable&role=student&status=active&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.users.0.id', $student->id);

        $this->patchJson('/api/v1/users/'.$student->id, [
            'name' => 'Updated Student',
            'email' => 'updated-student@example.test',
            'password' => 'updated-strong-password',
            'password_confirmation' => 'updated-strong-password',
            'role' => Role::PROFESSOR,
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/users/'.$student->id, [
            'name' => 'Updated Student',
            'email' => 'updated-student@example.test',
            'password' => 'updated-strong-password',
            'password_confirmation' => 'updated-strong-password',
            'profile' => [
                'student_number' => $student->student->student_number,
                'program_id' => $program->id,
                'admitted_on' => '2026-08-04',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Updated Student')
            ->assertJsonPath('data.user.profile.admitted_on', '2026-08-04');

        $this->assertTrue(Hash::check('updated-strong-password', $student->fresh()->password));

        $program->update(['status' => 'inactive']);
        $this->patchJson('/api/v1/users/'.$student->id, [
            'profile' => [
                'student_number' => $student->student->student_number,
                'program_id' => $program->id,
                'admitted_on' => '2026-08-04',
            ],
        ])->assertOk();

        $inactiveProgram = $this->makeProgram();
        $inactiveProgram->update(['status' => 'inactive']);
        $this->patchJson('/api/v1/users/'.$student->id, [
            'profile' => [
                'student_number' => $student->student->student_number,
                'program_id' => $inactiveProgram->id,
                'admitted_on' => '2026-08-04',
            ],
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/users/'.$inactive->id.'/status', ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'active');
    }

    public function test_only_administrators_can_create_accounts_or_choose_roles(): void
    {
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        Sanctum::actingAs($departmentPersonnel);

        $this->postJson('/api/v1/users', [])->assertForbidden();
    }

    public function test_department_personnel_can_manage_only_professor_and_student_accounts(): void
    {
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $professor = $this->makeUser(Role::PROFESSOR);
        $student = $this->makeUser(Role::STUDENT);
        $otherPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        Sanctum::actingAs($departmentPersonnel);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonCount(2, 'data.users');
        $this->getJson('/api/v1/users/'.$administrator->id)->assertForbidden();
        $this->getJson('/api/v1/users/'.$otherPersonnel->id)->assertForbidden();
        $this->patchJson('/api/v1/users/'.$professor->id, ['name' => 'Updated Professor'])
            ->assertOk();
        $this->patchJson('/api/v1/users/'.$professor->id, ['role' => Role::STUDENT])
            ->assertUnprocessable();
        $this->patchJson('/api/v1/users/'.$administrator->id, ['name' => 'Updated Admin'])
            ->assertForbidden();
        $this->patchJson('/api/v1/users/'.$student->id.'/status', ['status' => 'inactive'])
            ->assertOk();
        $this->patchJson('/api/v1/users/'.$administrator->id.'/status', ['status' => 'inactive'])
            ->assertForbidden();
    }

    public function test_professors_students_and_guests_cannot_access_account_management(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();

        foreach ([Role::PROFESSOR, Role::STUDENT] as $role) {
            Sanctum::actingAs($this->makeUser($role));
            $this->getJson('/api/v1/users')->assertForbidden();
        }
    }

    public function test_administrator_cannot_deactivate_their_own_account(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        Sanctum::actingAs($administrator);

        $this->patchJson('/api/v1/users/'.$administrator->id.'/status', ['status' => 'inactive'])
            ->assertUnprocessable();
        $this->assertSame('active', $administrator->fresh()->status);
    }

    public function test_another_administrator_can_be_deactivated_if_one_remains_active(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $anotherAdministrator = $this->makeUser(Role::ADMINISTRATOR);
        Sanctum::actingAs($administrator);

        $this->patchJson('/api/v1/users/'.$anotherAdministrator->id.'/status', ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'inactive');
    }

    public function test_options_include_active_programs_and_only_administrators_receive_role_choices(): void
    {
        $this->makeProgram();
        $this->makeRole(Role::ADMINISTRATOR);
        $this->makeRole(Role::DEPARTMENT_PERSONNEL);
        $this->makeRole(Role::PROFESSOR);
        $this->makeRole(Role::STUDENT);
        Sanctum::actingAs($this->makeUser(Role::DEPARTMENT_PERSONNEL));

        $this->getJson('/api/v1/users/options')
            ->assertOk()
            ->assertJsonCount(0, 'data.roles')
            ->assertJsonCount(1, 'data.programs');

        Sanctum::actingAs($this->makeUser(Role::ADMINISTRATOR));
        $this->getJson('/api/v1/users/options')
            ->assertOk()
            ->assertJsonCount(4, 'data.roles');
    }
}

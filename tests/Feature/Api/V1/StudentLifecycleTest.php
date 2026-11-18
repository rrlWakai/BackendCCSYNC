<?php

namespace Tests\Feature\Api\V1;

use App\Models\Role;
use App\Models\StudentRemoval;
use App\Services\Academic\PlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class StudentLifecycleTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_removing_a_student_revokes_access_and_restore_returns_the_account(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $program = $this->makeProgram();
        $studentUser = $this->makeUser(Role::STUDENT, [
            'name' => 'Removable Student',
            'email' => 'removable@example.test',
        ]);
        $student = $this->makeStudent($studentUser, $program);
        $token = $studentUser->createToken('student-session');

        Sanctum::actingAs($administrator);
        $this->postJson('/api/v1/students/'.$student->id.'/remove')
            ->assertOk()
            ->assertJsonPath('data.removal.student.id', $student->id)
            ->assertJsonPath('data.removal.status', StudentRemoval::STATUS_PENDING);

        $this->assertSame('inactive', $studentUser->fresh()->status);
        $this->assertSame('removed', $student->fresh()->status);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('student_activity_logs', [
            'student_id' => $student->id,
            'action' => 'student_removed',
        ]);

        $removal = StudentRemoval::query()->where('student_id', $student->id)->firstOrFail();
        $this->assertTrue($removal->removed_at->copy()->addDays(7)->equalTo($removal->restore_deadline));

        $this->postJson('/api/v1/students/'.$student->id.'/remove')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['student']]);

        $this->getJson('/api/v1/students/removed')
            ->assertOk()
            ->assertJsonPath('data.removals.0.student.id', $student->id)
            ->assertJsonPath('data.removals.0.student.student_number', $student->student_number);

        $this->postJson('/api/v1/students/'.$student->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.removal.status', StudentRemoval::STATUS_RESTORED);

        $this->assertSame('active', $studentUser->fresh()->status);
        $this->assertSame('active', $student->fresh()->status);
        $this->assertDatabaseHas('student_activity_logs', [
            'student_id' => $student->id,
            'action' => 'student_restored',
        ]);
    }

    public function test_expired_removals_are_anonymized_idempotently_and_preserve_academic_history(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $yearLevel = $this->makeYearLevel();
        $section = $this->makeSection($program, $term, $yearLevel);
        $studentUser = $this->makeUser(Role::STUDENT, [
            'name' => 'Expired Student',
            'email' => 'expired@example.test',
        ]);
        $student = $this->makeStudent($studentUser, $program);
        $placement = app(PlacementService::class)->createInitialPlacement($student, $section, $term);

        Sanctum::actingAs($administrator);
        $this->postJson('/api/v1/students/'.$student->id.'/remove')->assertOk();
        $this->travel(8)->days();
        $this->artisan('students:process-removals')->assertSuccessful();

        $this->assertDatabaseHas('student_removals', [
            'student_id' => $student->id,
            'status' => StudentRemoval::STATUS_PERMANENTLY_DELETED,
        ]);
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'status' => 'deleted',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $studentUser->id,
            'name' => 'Removed Student '.$student->id,
            'email' => 'removed-student-'.$studentUser->id.'@deleted.invalid',
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('student_academic_placements', ['id' => $placement->id]);
        $this->assertDatabaseHas('student_activity_logs', [
            'student_id' => $student->id,
            'action' => 'student_permanently_deleted',
        ]);

        $this->artisan('students:process-removals')->assertSuccessful();
        $this->assertSame(
            1,
            StudentRemoval::query()
                ->where('student_id', $student->id)
                ->where('status', StudentRemoval::STATUS_PERMANENTLY_DELETED)
                ->count(),
        );
    }

    public function test_activity_and_academic_history_endpoints_return_audit_and_placement_records(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $yearLevel = $this->makeYearLevel();
        $section = $this->makeSection($program, $term, $yearLevel);
        $studentUser = $this->makeUser(Role::STUDENT, ['name' => 'History Student']);
        $student = $this->makeStudent($studentUser, $program);
        app(PlacementService::class)->createInitialPlacement($student, $section, $term);

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/students/activity-logs')
            ->assertOk()
            ->assertJsonPath('data.logs.0.student.id', $student->id)
            ->assertJsonPath('data.logs.0.action', 'placement_recorded');

        $this->getJson('/api/v1/students/academic-history')
            ->assertOk()
            ->assertJsonPath('data.records.0.student.id', $student->id)
            ->assertJsonPath('data.records.0.is_current', true)
            ->assertJsonPath('data.records.0.section.id', $section->id);
    }

    public function test_restore_is_rejected_after_the_recovery_deadline(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $program = $this->makeProgram();
        $studentUser = $this->makeUser(Role::STUDENT);
        $student = $this->makeStudent($studentUser, $program);

        Sanctum::actingAs($administrator);
        $this->postJson('/api/v1/students/'.$student->id.'/remove')->assertOk();
        $this->travel(8)->days();
        $this->postJson('/api/v1/students/'.$student->id.'/restore')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['student']]);
    }

    public function test_only_administrators_and_department_personnel_can_manage_student_logs(): void
    {
        $professor = $this->makeUser(Role::PROFESSOR);
        $program = $this->makeProgram();
        $studentUser = $this->makeUser(Role::STUDENT);
        $student = $this->makeStudent($studentUser, $program);

        Sanctum::actingAs($professor);
        $this->getJson('/api/v1/students/activity-logs')->assertForbidden();
        $this->getJson('/api/v1/students/academic-history')->assertForbidden();
        $this->getJson('/api/v1/students/removed')->assertForbidden();
        $this->postJson('/api/v1/students/'.$student->id.'/remove')->assertForbidden();
        $this->postJson('/api/v1/students/'.$student->id.'/restore')->assertForbidden();
    }
}

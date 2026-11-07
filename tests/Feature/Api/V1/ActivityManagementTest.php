<?php

namespace Tests\Feature\Api\V1;

use App\Models\Activity;
use App\Models\ActivityTarget;
use App\Models\Role;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class ActivityManagementTest extends TestCase
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

    public function test_administrator_can_create_a_draft_and_local_schedule_is_saved_as_utc(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $room = $this->makeRoom();

        $response = $this->actingAs($administrator)
            ->postJson('/api/v1/activities', $this->payload([
                'room_id' => $room->id,
                'venue_name' => null,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.activity.status', 'draft')
            ->assertJsonPath('data.activity.activity_state', 'draft')
            ->assertJsonPath('data.activity.room.code', $room->code)
            ->assertJsonPath('data.activity.starts_at', '2026-12-10T02:00:00+00:00')
            ->assertJsonPath('data.activity.ends_at', '2026-12-10T04:00:00+00:00');

        $activityId = $response->json('data.activity.id');
        $this->assertDatabaseHas('activity_history', [
            'activity_id' => $activityId,
            'acted_by_user_id' => $administrator->id,
            'action' => 'created',
            'new_status' => 'draft',
        ]);
    }

    public function test_department_personnel_can_manage_only_custom_activities_inside_assigned_programs(): void
    {
        $assignedProgram = $this->makeProgram();
        $outsideProgram = $this->makeProgram();
        $departmentPersonnel = $this->makeUser(Role::DEPARTMENT_PERSONNEL);
        $departmentPersonnel->departmentPersonnelPrograms()->attach($assignedProgram->id);

        $created = $this->actingAs($departmentPersonnel)
            ->postJson('/api/v1/activities', $this->payload([
                'audience_scope' => 'custom',
                'targets' => ['program_ids' => [$assignedProgram->id]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.activity.targets.program_ids.0', $assignedProgram->id);
        $activityId = $created->json('data.activity.id');

        $this->getJson('/api/v1/activities/manage')
            ->assertOk()
            ->assertJsonCount(1, 'data.activities');
        $this->patchJson('/api/v1/activities/'.$activityId, ['title' => 'Scoped edit'])
            ->assertOk();

        $this->postJson('/api/v1/activities', $this->payload([
            'audience_scope' => 'custom',
            'targets' => ['program_ids' => [$outsideProgram->id]],
        ]))->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['targets.program_ids']]);

        $outside = $this->createActivity($this->makeUser(Role::ADMINISTRATOR), [
            'audience_scope' => 'custom',
        ]);
        ActivityTarget::create([
            'activity_id' => $outside->id,
            'program_id' => $assignedProgram->id,
        ]);
        ActivityTarget::create([
            'activity_id' => $outside->id,
            'program_id' => $outsideProgram->id,
        ]);

        $this->getJson('/api/v1/activities/manage')
            ->assertOk()
            ->assertJsonCount(1, 'data.activities');
        $this->patchJson('/api/v1/activities/'.$outside->id, ['title' => 'Out-of-scope edit'])
            ->assertForbidden();
        $this->getJson('/api/v1/activities/'.$outside->id.'/history')->assertForbidden();
    }

    public function test_student_and_professor_are_view_only_and_drafts_are_hidden(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $draft = $this->createActivity($administrator);
        $payload = $this->payload();

        foreach ([Role::STUDENT, Role::PROFESSOR] as $role) {
            $actor = $this->makeUser($role);
            $this->actingAs($actor)
                ->getJson('/api/v1/activities/manage')
                ->assertForbidden();
            $this->postJson('/api/v1/activities', $payload)->assertForbidden();
            $this->patchJson('/api/v1/activities/'.$draft->id, ['title' => 'No access'])
                ->assertForbidden();
            $this->postJson('/api/v1/activities/'.$draft->id.'/publish')->assertForbidden();
            $this->postJson('/api/v1/activities/'.$draft->id.'/cancel', ['reason' => 'No longer needed'])
                ->assertForbidden();
            $this->postJson('/api/v1/activities/'.$draft->id.'/complete')->assertForbidden();
            $this->getJson('/api/v1/activities/'.$draft->id.'/history')->assertForbidden();
            $this->getJson('/api/v1/activities/'.$draft->id)->assertForbidden();
            $this->getJson('/api/v1/activities')->assertOk()->assertJsonCount(0, 'data.activities');
        }
    }

    public function test_activity_audience_intersects_role_program_year_section_and_user_targets(): void
    {
        $term = $this->makeAcademicTerm();
        $program = $this->makeProgram();
        $studentUser = $this->makeUser(Role::STUDENT);
        $student = $this->makeStudent($studentUser, $program);
        $yearLevel = $this->makeYearLevel(2);
        $section = $this->makeSection($program, $term, $yearLevel);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        StudentSubjectEnrollment::create([
            'student_id' => $student->id,
            'subject_offering_id' => $offering->id,
            'status' => 'enrolled',
            'enrolled_at' => '2026-08-01 08:00:00',
        ]);
        $professorUser = $this->makeUser(Role::PROFESSOR);
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $activity = $this->createActivity($administrator, [
            'status' => 'published',
            'audience_scope' => 'custom',
        ]);
        foreach ([
            ['role_id' => $studentUser->role_id],
            ['program_id' => $program->id],
            ['year_level_id' => $yearLevel->id],
            ['section_id' => $section->id],
            ['user_id' => $studentUser->id],
        ] as $target) {
            ActivityTarget::create(['activity_id' => $activity->id, ...$target]);
        }

        $this->actingAs($studentUser)
            ->getJson('/api/v1/activities')
            ->assertOk()
            ->assertJsonCount(1, 'data.activities');
        $this->getJson('/api/v1/activities/'.$activity->id)->assertOk();

        $this->actingAs($professorUser)
            ->getJson('/api/v1/activities')
            ->assertOk()
            ->assertJsonCount(0, 'data.activities');
        $this->getJson('/api/v1/activities/'.$activity->id)->assertForbidden();
    }

    public function test_published_cancelled_and_completed_activities_remain_visible_to_their_audience(): void
    {
        $student = $this->makeUser(Role::STUDENT);
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $future = $this->createActivity($administrator, [
            'status' => 'published',
        ]);
        $cancelled = $this->createActivity($administrator, [
            'status' => 'cancelled',
            'cancelled_at' => CarbonImmutable::now(),
            'cancellation_reason' => 'Venue unavailable',
        ]);
        $completed = $this->createActivity($administrator, [
            'status' => 'completed',
            'starts_at' => CarbonImmutable::now()->subHours(3),
            'ends_at' => CarbonImmutable::now()->subHour(),
            'completed_at' => CarbonImmutable::now(),
        ]);

        $this->actingAs($student)
            ->getJson('/api/v1/activities?status=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data.activities')
            ->assertJsonPath('data.activities.0.id', $future->id);
        $this->getJson('/api/v1/activities?status=cancelled')
            ->assertOk()
            ->assertJsonPath('data.activities.0.id', $cancelled->id);
        $this->getJson('/api/v1/activities?status=completed')
            ->assertOk()
            ->assertJsonPath('data.activities.0.id', $completed->id);
        $this->getJson('/api/v1/activities/'.$cancelled->id)
            ->assertOk()
            ->assertJsonPath('data.activity.cancellation_reason', 'Venue unavailable');
    }

    public function test_end_time_must_follow_start_and_only_ended_activities_can_be_completed(): void
    {
        $administrator = $this->makeUser(Role::ADMINISTRATOR);
        $this->actingAs($administrator)
            ->postJson('/api/v1/activities', $this->payload([
                'ends_at' => '2026-12-10T10:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['ends_at']]);

        $future = $this->createActivity($administrator, ['status' => 'published']);
        $this->postJson('/api/v1/activities/'.$future->id.'/complete')
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['status']]);

        $past = $this->createActivity($administrator, [
            'status' => 'published',
            'starts_at' => CarbonImmutable::now()->subHours(3),
            'ends_at' => CarbonImmutable::now()->subHour(),
        ]);
        $this->postJson('/api/v1/activities/'.$past->id.'/complete')
            ->assertOk()
            ->assertJsonPath('data.activity.status', 'completed');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'CCS activity',
            'summary' => 'Activity summary',
            'description' => 'Full activity description.',
            'category' => 'seminar',
            'organizer_name' => 'CCS Department',
            'room_id' => null,
            'venue_name' => 'CCS Auditorium',
            'location_details' => 'Main building',
            'audience_scope' => 'all',
            'starts_at' => '2026-12-10T10:00',
            'ends_at' => '2026-12-10T12:00',
            'targets' => [
                'role_ids' => [],
                'program_ids' => [],
                'year_level_ids' => [],
                'section_ids' => [],
                'user_ids' => [],
            ],
        ], $overrides);
    }

    private function createActivity(User $author, array $overrides = []): Activity
    {
        return Activity::create(array_merge([
            'created_by_user_id' => $author->id,
            'title' => 'CCS activity',
            'summary' => 'Activity summary',
            'description' => 'Full activity description.',
            'category' => 'seminar',
            'organizer_name' => 'CCS Department',
            'room_id' => null,
            'venue_name' => 'CCS Auditorium',
            'location_details' => 'Main building',
            'audience_scope' => 'all',
            'starts_at' => CarbonImmutable::now()->addDays(3),
            'ends_at' => CarbonImmutable::now()->addDays(3)->addHours(2),
            'status' => 'draft',
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'completed_at' => null,
            'completed_by_user_id' => null,
        ], $overrides));
    }
}

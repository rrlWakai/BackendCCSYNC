<?php

namespace Tests\Feature\Api\V1;

use App\Models\Professor;
use App\Models\Role;
use App\Models\SchedulingMeetingPlan;
use App\Models\SchedulingTimeGrid;
use App\Services\Scheduling\DatabaseSchedulingConfigurationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\CreatesAcademicData;
use Tests\TestCase;

class SchedulingConfigurationTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    public function test_administrator_can_approve_time_grid_and_provider_exposes_contract_payload(): void
    {
        $term = $this->makeAcademicTerm();
        $admin = $this->makeUser(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        $response = $this->postJson('/api/v1/scheduling/configuration/time-grids', [
            'academic_term_id' => $term->id,
            'timezone' => 'Asia/Manila',
            'notes' => 'Institutional grid',
            'days' => [
                [
                    'day_of_week' => 1,
                    'operating_start' => '08:00',
                    'operating_end' => '12:00',
                    'slots' => [
                        ['start_time' => '08:00', 'end_time' => '09:00'],
                        ['start_time' => '09:00', 'end_time' => '10:00'],
                    ],
                ],
                [
                    'day_of_week' => 3,
                    'operating_start' => '13:00',
                    'operating_end' => '15:00',
                    'slots' => [
                        ['start_time' => '13:00', 'end_time' => '14:00'],
                    ],
                ],
            ],
        ])->assertCreated();

        $gridId = $response->json('data.time_grid.id');
        $this->assertSame('draft', $response->json('data.time_grid.status'));
        $this->assertSame(1, $response->json('data.time_grid.version'));

        $this->postJson("/api/v1/scheduling/configuration/time-grids/{$gridId}/approve")
            ->assertOk()
            ->assertJsonPath('data.time_grid.status', 'approved');

        $grid = SchedulingTimeGrid::query()->findOrFail($gridId);
        $payload = app(DatabaseSchedulingConfigurationProvider::class)->timeGrid($term->fresh());

        $this->assertNotNull($payload);
        $this->assertSame((string) $grid->id, $payload['configuration_id']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame('Asia/Manila', $payload['timezone']);
        $this->assertSame([1, 3], array_column($payload['days'], 'day_of_week'));
        $this->assertSame(['08:00', '09:00'], $payload['days'][0]['valid_start_times']);
        $this->assertSame(['13:00'], $payload['days'][1]['valid_start_times']);

        $this->getJson('/api/v1/scheduling/readiness?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.readiness.capabilities.time_grid', true);
    }

    public function test_department_personnel_can_draft_but_cannot_approve_time_grid(): void
    {
        $term = $this->makeAcademicTerm();
        $this->actingAs($this->makeUser(Role::DEPARTMENT_PERSONNEL));

        $response = $this->postJson('/api/v1/scheduling/configuration/time-grids', [
            'academic_term_id' => $term->id,
            'timezone' => 'Asia/Manila',
            'days' => [
                [
                    'day_of_week' => 1,
                    'operating_start' => '08:00',
                    'operating_end' => '10:00',
                    'slots' => [
                        ['start_time' => '08:00', 'end_time' => '10:00'],
                    ],
                ],
            ],
        ])->assertCreated();

        $this->postJson(
            '/api/v1/scheduling/configuration/time-grids/'.$response->json('data.time_grid.id').'/approve'
        )->assertForbidden();

        $this->assertSame(
            'draft',
            SchedulingTimeGrid::query()->findOrFail($response->json('data.time_grid.id'))->status,
        );
    }

    public function test_time_grid_request_rejects_overlapping_slots(): void
    {
        $term = $this->makeAcademicTerm();
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/scheduling/configuration/time-grids', [
            'academic_term_id' => $term->id,
            'timezone' => 'Asia/Manila',
            'days' => [
                [
                    'day_of_week' => 1,
                    'operating_start' => '08:00',
                    'operating_end' => '12:00',
                    'slots' => [
                        ['start_time' => '08:00', 'end_time' => '10:00'],
                        ['start_time' => '09:00', 'end_time' => '11:00'],
                    ],
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days.0.slots.1.start_time');
    }

    public function test_meeting_plan_lifecycle_supersedes_previous_version_and_provider_exposes_requirements(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $payload = [
            'subject_offering_id' => $offering->id,
            'notes' => 'One lecture and one laboratory block.',
            'requirements' => [
                [
                    'meeting_type' => 'lecture',
                    'frequency_per_week' => 1,
                    'duration_minutes' => 60,
                    'modality' => 'face_to_face',
                    'required_room_type' => 'lecture',
                    'mandatory' => true,
                    'allowed_days' => [1, 3],
                    'valid_start_times' => ['08:00', '09:00'],
                ],
                [
                    'meeting_type' => 'laboratory',
                    'frequency_per_week' => 1,
                    'duration_minutes' => 120,
                    'modality' => 'face_to_face',
                    'required_room_type' => 'laboratory',
                    'mandatory' => true,
                ],
            ],
        ];

        $first = $this->postJson('/api/v1/scheduling/configuration/meeting-plans', $payload)
            ->assertCreated()
            ->assertJsonPath('data.meeting_plan.status', 'draft')
            ->assertJsonPath('data.meeting_plan.version', 1);

        $firstId = $first->json('data.meeting_plan.id');

        $this->postJson("/api/v1/scheduling/configuration/meeting-plans/{$firstId}/approve")
            ->assertOk()
            ->assertJsonPath('data.meeting_plan.status', 'approved');

        $providerPayload = app(DatabaseSchedulingConfigurationProvider::class)
            ->meetingPlans($term->fresh(), new Collection([$offering]));

        $this->assertArrayHasKey($offering->id, $providerPayload);
        $this->assertCount(2, $providerPayload[$offering->id]['meeting_requirements']);
        $this->assertSame([1, 3], $providerPayload[$offering->id]['meeting_requirements'][0]['allowed_days']);
        $this->assertSame(['08:00', '09:00'], $providerPayload[$offering->id]['meeting_requirements'][0]['valid_start_times']);

        $second = $this->postJson("/api/v1/scheduling/configuration/meeting-plans/{$firstId}/revise")
            ->assertCreated()
            ->assertJsonPath('data.meeting_plan.version', 2);

        $secondId = $second->json('data.meeting_plan.id');
        $payload['requirements'][0]['frequency_per_week'] = 2;

        $this->patchJson("/api/v1/scheduling/configuration/meeting-plans/{$secondId}", $payload)
            ->assertOk();

        $this->postJson("/api/v1/scheduling/configuration/meeting-plans/{$secondId}/approve")
            ->assertOk()
            ->assertJsonPath('data.meeting_plan.status', 'approved');

        $this->assertSame(
            'superseded',
            SchedulingMeetingPlan::query()->findOrFail($firstId)->status,
        );

        $providerPayload = app(DatabaseSchedulingConfigurationProvider::class)
            ->meetingPlans($term->fresh(), new Collection([$offering]));

        $this->assertSame(2, $providerPayload[$offering->id]['meeting_plan_version']);
        $this->assertSame(2, $providerPayload[$offering->id]['meeting_requirements'][0]['frequency_per_week']);
    }

    public function test_full_time_policy_lifecycle_exposes_contract_payload_and_readiness(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $this->makeFacultyAssignment($professor, $offering);
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $response = $this->postJson('/api/v1/scheduling/configuration/full-time-policies', [
            'academic_term_id' => $term->id,
            'standard_days' => [1, 2],
            'exceptions' => [
                [
                    'professor_id' => $professor->id,
                    'authorized_additional_days' => [3, 4],
                ],
            ],
        ])->assertCreated();

        $policyId = $response->json('data.full_time_policy.id');
        $this->assertSame('draft', $response->json('data.full_time_policy.status'));
        $this->assertSame(1, $response->json('data.full_time_policy.version'));

        $this->postJson("/api/v1/scheduling/configuration/full-time-policies/{$policyId}/approve")
            ->assertOk()
            ->assertJsonPath('data.full_time_policy.status', 'approved');

        $payload = app(DatabaseSchedulingConfigurationProvider::class)
            ->fullTimeWorkdayPolicy($term->fresh(), new Collection([$professor]));

        $this->assertNotNull($payload);
        $this->assertSame((string) $policyId, $payload['policy_id']);
        $this->assertSame(1, $payload['policy_version']);
        $this->assertSame([1, 2], $payload['standard_days']);
        $this->assertSame([
            [
                'professor_id' => (string) $professor->id,
                'authorized_additional_days' => [3, 4],
            ],
        ], $payload['exceptions']);

        $this->getJson('/api/v1/scheduling/readiness?academic_term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.readiness.capabilities.full_time_workday_policy', true);
    }

    public function test_full_time_policy_request_rejects_legacy_day_of_week_exception_key(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/scheduling/configuration/full-time-policies', [
            'academic_term_id' => $term->id,
            'standard_days' => [1, 2],
            'exceptions' => [
                [
                    'professor_id' => $professor->id,
                    'day_of_week' => 3,
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('exceptions.0.authorized_additional_days');
    }

    public function test_part_time_quota_is_exposed_as_contract_minutes(): void
    {
        $program = $this->makeProgram();
        $user = $this->makeUser(Role::PROFESSOR);
        $professor = Professor::query()->findOrFail(
            $this->makeProfessor($user, $program)->id,
        );

        $professor->update([
            'employment_type' => 'part_time',
            'weekly_teaching_hours_quota' => 12.5,
        ]);

        $this->assertSame(
            750,
            app(DatabaseSchedulingConfigurationProvider::class)->partTimeWeeklyTargetMinutes($professor->fresh()),
        );
    }

    public function test_meeting_plan_changes_are_blocked_for_published_schedules(): void
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $this->makeClassSchedule($offering);
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/scheduling/configuration/meeting-plans', [
            'subject_offering_id' => $offering->id,
            'requirements' => [
                [
                    'meeting_type' => 'lecture',
                    'frequency_per_week' => 1,
                    'duration_minutes' => 60,
                    'modality' => 'face_to_face',
                    'mandatory' => true,
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subject_offering_id');
    }
}

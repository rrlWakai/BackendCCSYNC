<?php

namespace Tests\Feature\Api\V1;

use App\Models\AcademicTerm;
use App\Models\FacultyAssignment;
use App\Models\Professor;
use App\Models\Role;
use App\Models\Room;
use App\Models\Section;
use App\Models\SubjectOffering;
use App\Services\Scheduling\Contracts\SchedulingConfigurationProvider;
use App\Services\Scheduling\SchedulerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesAcademicData;
use Tests\Support\FakeSchedulingConfigurationProvider;
use Tests\TestCase;

class SchedulingSolveTest extends TestCase
{
    use CreatesAcademicData;
    use RefreshDatabase;

    private const CORRELATION_ID = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    public function test_administrator_can_request_a_validated_scheduling_candidate(): void
    {
        $context = $this->seedSolvableTerm();
        $this->configureScheduler();
        $this->app->instance(
            SchedulingConfigurationProvider::class,
            $this->fakeConfiguration($context['offering']),
        );

        $captured = null;

        Http::fake(function (Request $request) use (&$captured): mixed {
            $captured = $request->data();

            return Http::response($this->candidateResponse($captured), 200);
        });

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/scheduling/solve', [
            'academic_term_id' => $context['term']->id,
            'correlation_id' => self::CORRELATION_ID,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.solver_status', 'OPTIMAL')
            ->assertJsonPath('data.result.result_status', 'FEASIBLE')
            ->assertJsonPath('data.result.validation_summary.complete_candidate', true);

        $this->assertIsArray($captured);
        $this->assertSame('1.0', $captured['contract_version']);
        $this->assertSame(self::CORRELATION_ID, $captured['metadata']['correlation_id']);
        $this->assertSame([(string) $context['offering']->id], $captured['generation_scope']['included_offering_ids']);
        $this->assertCount(1, $captured['offerings']);
        $this->assertCount(1, $captured['professor_assignments']);

        Http::assertSent(function (Request $request) use ($captured): bool {
            $data = $request->data();

            return $request->url() === 'http://scheduler.test/api/v1/solve'
                && $request->hasHeader('X-CCSync-Key', 'test-scheduler-key')
                && $data['metadata']['snapshot_sha256'] === $captured['metadata']['snapshot_sha256'];
        });
    }

    public function test_solve_returns_actionable_issues_when_configuration_is_incomplete(): void
    {
        $context = $this->seedSolvableTerm();
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $response = $this->postJson('/api/v1/scheduling/solve', [
            'academic_term_id' => $context['term']->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'scheduling_request_incomplete');

        $codes = collect($response->json('errors.issues'))->pluck('code');

        $this->assertTrue($codes->contains('time_grid_not_configured'));
        $this->assertTrue($codes->contains('meeting_plan_missing'));
    }

    public function test_solve_rejects_legacy_full_time_exception_keys_before_calling_scheduler(): void
    {
        $context = $this->seedSolvableTerm();
        $this->configureScheduler();
        $this->app->instance(SchedulingConfigurationProvider::class, new FakeSchedulingConfigurationProvider(
            timeGrid: [
                'configuration_id' => 'grid-1',
                'approved_at' => '2026-07-01T00:00:00Z',
                'timezone' => 'Asia/Manila',
                'version' => 1,
                'days' => [[
                    'day_of_week' => 1,
                    'operating_start' => '08:00',
                    'operating_end' => '17:00',
                    'valid_start_times' => ['08:00', '09:00', '10:00'],
                ]],
            ],
            meetingPlans: [
                $context['offering']->id => [
                    'meeting_plan_id' => 'plan-1',
                    'meeting_plan_version' => 1,
                    'meeting_plan_approved_at' => '2026-07-01T00:00:00Z',
                    'meeting_requirements' => [[
                        'id' => 'req-1',
                        'meeting_type' => 'lecture',
                        'modality' => 'face_to_face',
                        'duration_minutes' => 60,
                        'frequency_per_week' => 1,
                        'mandatory' => true,
                        'allowed_days' => [1],
                        'valid_start_times' => ['08:00'],
                        'required_room_type' => 'lecture',
                    ]],
                ],
            ],
            fullTimePolicy: [
                'policy_id' => 'policy-1',
                'policy_version' => 1,
                'standard_days' => [1, 2, 3, 4, 5],
                'exceptions' => [[
                    'professor_id' => (string) $context['professor']->id,
                    'day_of_week' => 3,
                ]],
            ],
        ));
        Http::fake();

        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $response = $this->postJson('/api/v1/scheduling/solve', [
            'academic_term_id' => $context['term']->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'scheduling_request_incomplete');

        $codes = collect($response->json('errors.issues'))->pluck('code');

        $this->assertTrue($codes->contains('unsupported_full_time_workday_exception_field'));
        $this->assertTrue($codes->contains('missing_full_time_workday_exception_field'));
        Http::assertNothingSent();
    }

    public function test_solve_rejects_malformed_correlation_id(): void
    {
        $context = $this->seedSolvableTerm();
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        $this->postJson('/api/v1/scheduling/solve', [
            'academic_term_id' => $context['term']->id,
            'correlation_id' => 'not-a-uuid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('correlation_id');
    }

    public function test_solve_requires_scheduling_management_permission(): void
    {
        $context = $this->seedSolvableTerm();
        $this->actingAs($this->makeUser(Role::PROFESSOR));

        $this->postJson('/api/v1/scheduling/solve', [
            'academic_term_id' => $context['term']->id,
        ])->assertForbidden();
    }

    /**
     * @return array{term: AcademicTerm, section: Section, offering: SubjectOffering, professor: Professor, facultyAssignment: FacultyAssignment, room: Room}
     */
    private function seedSolvableTerm(): array
    {
        $program = $this->makeProgram();
        $term = $this->makeAcademicTerm();
        $section = $this->makeSection($program, $term);
        $offering = $this->makeOffering($term, $section, $this->makeSubject());
        $professor = $this->makeProfessor($this->makeUser(Role::PROFESSOR), $program);
        $facultyAssignment = $this->makeFacultyAssignment($professor, $offering);
        $room = $this->makeRoom();

        return compact('term', 'section', 'offering', 'professor', 'facultyAssignment', 'room');
    }

    private function configureScheduler(): void
    {
        config([
            'scheduler.url' => 'http://scheduler.test',
            'scheduler.api_key' => 'test-scheduler-key',
            'scheduler.contract_version' => '1.0',
            'scheduler.solver' => [
                'time_limit_ms' => 5000,
                'worker_count' => 1,
                'random_seed' => 42,
                'objective_weights' => [
                    'part_time_target_deviation_per_minute' => 1,
                    'full_time_load_spread_per_minute' => 1,
                    'professor_gap_per_minute' => 1,
                    'section_gap_per_minute' => 1,
                    'room_capacity_waste_per_seat' => 1,
                    'section_daily_load_spread_per_minute' => 1,
                    'preferred_window_miss_per_meeting' => 1,
                    'optional_meeting_omission' => 1,
                    'published_placement_change_per_meeting' => 1,
                ],
            ],
        ]);

        $this->app->forgetInstance(SchedulerClient::class);
    }

    private function fakeConfiguration(SubjectOffering $offering): FakeSchedulingConfigurationProvider
    {
        return new FakeSchedulingConfigurationProvider(
            timeGrid: [
                'configuration_id' => 'grid-1',
                'approved_at' => '2026-07-01T00:00:00Z',
                'timezone' => 'Asia/Manila',
                'version' => 1,
                'days' => [
                    [
                        'day_of_week' => 1,
                        'operating_start' => '08:00',
                        'operating_end' => '17:00',
                        'valid_start_times' => ['08:00', '09:00', '10:00'],
                    ],
                ],
            ],
            meetingPlans: [
                $offering->id => [
                    'meeting_plan_id' => 'plan-1',
                    'meeting_plan_version' => 1,
                    'meeting_plan_approved_at' => '2026-07-01T00:00:00Z',
                    'meeting_requirements' => [
                        [
                            'id' => 'req-1',
                            'meeting_type' => 'lecture',
                            'modality' => 'face_to_face',
                            'duration_minutes' => 60,
                            'frequency_per_week' => 1,
                            'mandatory' => true,
                            'allowed_days' => [1],
                            'valid_start_times' => ['08:00'],
                            'required_room_type' => 'lecture',
                        ],
                    ],
                ],
            ],
            fullTimePolicy: [
                'policy_id' => 'policy-1',
                'policy_version' => 1,
                'standard_days' => [1, 2, 3, 4, 5],
                'exceptions' => [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function candidateResponse(array $payload): array
    {
        $offering = $payload['offerings'][0];
        $requirement = $offering['meeting_requirements'][0];
        $professorAssignment = $payload['professor_assignments'][0];

        $offeringId = (string) $offering['id'];
        $sectionId = (string) $offering['section_id'];
        $requirementId = (string) $requirement['id'];
        $professorId = (string) $professorAssignment['professor_id'];
        $facultyAssignmentId = (string) $professorAssignment['id'];
        $roomId = (string) $payload['rooms'][0]['id'];
        $duration = (int) $requirement['duration_minutes'];

        return [
            'contract_version' => $payload['contract_version'],
            'generation_id' => $payload['metadata']['generation_id'],
            'academic_term_id' => (string) $payload['academic_term']['id'],
            'snapshot_sha256' => $payload['metadata']['snapshot_sha256'],
            'correlation_id' => $payload['metadata']['correlation_id'],
            'solver_status' => 'OPTIMAL',
            'result_status' => 'FEASIBLE',
            'started_at' => '2026-08-03T00:00:00Z',
            'completed_at' => '2026-08-03T00:00:01Z',
            'solver_execution_ms' => 1000,
            'objective_value' => 0,
            'best_bound' => 0,
            'optimality_proven' => true,
            'time_limit_reached' => false,
            'assignments' => [
                [
                    'meeting_instance_id' => $requirementId.':1',
                    'source_requirement_id' => $requirementId,
                    'occurrence_number' => 1,
                    'subject_offering_ids' => [$offeringId],
                    'section_ids' => [$sectionId],
                    'professor_id' => $professorId,
                    'professor_assignments' => [
                        [
                            'subject_offering_id' => $offeringId,
                            'faculty_assignment_id' => $facultyAssignmentId,
                            'assignment_role' => 'lead',
                        ],
                    ],
                    'is_primary' => true,
                    'room_id' => $roomId,
                    'meeting_type' => 'lecture',
                    'modality' => 'face_to_face',
                    'day_of_week' => 1,
                    'start_time' => '08:00',
                    'end_time' => '09:00',
                    'duration_minutes' => $duration,
                ],
            ],
            'validation_summary' => [
                'independent_validation_status' => 'passed',
                'scheduled_meeting_count' => 1,
                'required_mandatory_meeting_count' => 1,
                'desired_meeting_count' => 1,
                'required_offering_count' => 1,
                'represented_offering_count' => 1,
                'complete_candidate' => true,
                'validation_errors' => [],
            ],
            'full_time_distribution' => [
                'minimum_assigned_minutes' => $duration,
                'maximum_assigned_minutes' => $duration,
                'spread_minutes' => 0,
            ],
            'placement_metrics' => [
                'comparable_meeting_count' => 0,
                'retained_placement_count' => 0,
                'changed_placement_count' => 0,
                'unmatched_baseline_schedule_count' => 0,
            ],
            'unscheduled_mandatory_meeting_ids' => null,
            'diagnostics' => [],
            'workload_metrics' => [
                [
                    'professor_id' => $professorId,
                    'employment_type' => 'full_time',
                    'assigned_teaching_minutes' => $duration,
                    'weekly_target_minutes' => null,
                    'signed_target_deviation_minutes' => null,
                    'absolute_target_deviation_minutes' => null,
                ],
            ],
            'warnings' => [],
        ];
    }
}

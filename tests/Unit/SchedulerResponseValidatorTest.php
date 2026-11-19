<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\SchedulerResponseValidator;
use Tests\TestCase;

class SchedulerResponseValidatorTest extends TestCase
{
    public function test_valid_response_is_accepted(): void
    {
        $this->assertSame([], SchedulerResponseValidator::validate(
            $this->response(),
            $this->request(),
        ));
    }

    public function test_identity_and_assignment_mismatches_are_rejected(): void
    {
        $identityMismatch = $this->response();
        $identityMismatch['generation_id'] = 'c33ce000-3333-4333-8333-333333333333';
        $this->assertContains(
            'response_generation_id_mismatch',
            SchedulerResponseValidator::validate($identityMismatch, $this->request()),
        );

        $unknownProfessor = $this->response();
        $unknownProfessor['assignments'][0]['professor_id'] = '999';
        $this->assertContains(
            'assignment_0_unknown_professor',
            SchedulerResponseValidator::validate($unknownProfessor, $this->request()),
        );
    }

    public function test_counts_and_duplicate_meetings_are_rejected(): void
    {
        $response = $this->response();
        $response['validation_summary']['scheduled_meeting_count'] = 3;
        $response['assignments'][] = $response['assignments'][0];

        $errors = SchedulerResponseValidator::validate($response, $this->request());

        $this->assertContains('scheduled_meeting_count_mismatch', $errors);
        $this->assertContains('duplicate_meeting_instance_id:req-7001-lecture:1', $errors);
    }

    public function test_result_status_and_term_identity_are_validated(): void
    {
        $mismatchedStatus = $this->response();
        $mismatchedStatus['result_status'] = 'TIME_LIMIT';
        $mismatchedStatus['time_limit_reached'] = true;
        $this->assertContains(
            'result_status_mismatch',
            SchedulerResponseValidator::validate($mismatchedStatus, $this->request()),
        );

        $mismatchedTerm = $this->response();
        $mismatchedTerm['academic_term_id'] = '43';
        $this->assertContains(
            'response_academic_term_id_mismatch',
            SchedulerResponseValidator::validate($mismatchedTerm, $this->request()),
        );

        $timeLimit = $this->response();
        $timeLimit['solver_status'] = 'UNKNOWN';
        $timeLimit['result_status'] = 'TIME_LIMIT';
        $timeLimit['time_limit_reached'] = true;
        $timeLimit['optimality_proven'] = false;
        $timeLimit['objective_value'] = null;
        $timeLimit['best_bound'] = null;
        $timeLimit['assignments'] = [];
        $timeLimit['validation_summary'] = [
            'independent_validation_status' => 'not_run',
            'scheduled_meeting_count' => 0,
            'required_mandatory_meeting_count' => 1,
            'desired_meeting_count' => 1,
            'required_offering_count' => 1,
            'represented_offering_count' => 0,
            'complete_candidate' => false,
            'validation_errors' => [],
        ];
        $timeLimit['diagnostics'] = [[
            'code' => 'TIME_LIMIT_NO_CANDIDATE',
            'message' => 'The solver reached its configured time limit.',
            'evidence' => 'solver_proven',
            'meeting_requirement_ids' => [],
            'related_entity_ids' => [],
        ]];

        $this->assertSame([], SchedulerResponseValidator::validate($timeLimit, $this->request()));
    }

    /**
     * @return array<string, mixed>
     */
    private function request(): array
    {
        return [
            'academic_term' => ['id' => 42],
            'metadata' => [
                'generation_id' => 'a11ce000-1111-4111-8111-111111111111',
                'snapshot_sha256' => str_repeat('0', 64),
                'correlation_id' => 'b22ce000-2222-4222-8222-222222222222',
            ],
            'generation_scope' => [
                'included_offering_ids' => ['7001'],
            ],
            'offerings' => [['id' => '7001']],
            'sections' => [['id' => '201']],
            'professors' => [['id' => '501']],
            'rooms' => [['id' => '30']],
            'professor_assignments' => [[
                'id' => '9001',
                'professor_id' => '501',
                'subject_offering_id' => '7001',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function response(): array
    {
        return [
            'contract_version' => '1.0',
            'generation_id' => 'a11ce000-1111-4111-8111-111111111111',
            'academic_term_id' => '42',
            'snapshot_sha256' => str_repeat('0', 64),
            'correlation_id' => 'b22ce000-2222-4222-8222-222222222222',
            'solver_status' => 'OPTIMAL',
            'result_status' => 'FEASIBLE',
            'started_at' => '2026-01-15T10:00:01Z',
            'completed_at' => '2026-01-15T10:00:02Z',
            'solver_execution_ms' => 950,
            'objective_value' => 0,
            'best_bound' => 0,
            'optimality_proven' => true,
            'time_limit_reached' => false,
            'assignments' => [[
                'meeting_instance_id' => 'req-7001-lecture:1',
                'source_requirement_id' => 'req-7001-lecture',
                'occurrence_number' => 1,
                'subject_offering_ids' => ['7001'],
                'section_ids' => ['201'],
                'professor_id' => '501',
                'professor_assignments' => [[
                    'subject_offering_id' => '7001',
                    'faculty_assignment_id' => '9001',
                    'assignment_role' => 'lead',
                ]],
                'is_primary' => true,
                'room_id' => '30',
                'meeting_type' => 'lecture',
                'modality' => 'face_to_face',
                'day_of_week' => 1,
                'start_time' => '09:00',
                'end_time' => '10:30',
                'duration_minutes' => 90,
            ]],
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
            'unscheduled_mandatory_meeting_ids' => null,
            'diagnostics' => [],
            'workload_metrics' => [],
            'full_time_distribution' => null,
            'placement_metrics' => [
                'comparable_meeting_count' => 0,
                'retained_placement_count' => 0,
                'changed_placement_count' => 0,
                'unmatched_baseline_schedule_count' => 0,
            ],
            'warnings' => [],
        ];
    }
}

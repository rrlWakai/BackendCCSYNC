<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use DateTimeImmutable;
use Throwable;

final class SchedulerResponseValidator
{
    private const SOLVER_STATUSES = ['OPTIMAL', 'FEASIBLE', 'INFEASIBLE', 'UNKNOWN'];

    private const RESULT_STATUSES = ['FEASIBLE', 'INFEASIBLE', 'UNKNOWN', 'TIME_LIMIT'];

    private const VALIDATION_STATUSES = ['passed', 'failed', 'not_run'];

    private const MEETING_TYPES = ['lecture', 'laboratory', 'seminar', 'other'];

    private const MODALITIES = ['face_to_face', 'online', 'hybrid'];

    private const ASSIGNMENT_ROLES = ['lead', 'co_instructor', 'lab_instructor', 'substitute'];

    private const DIAGNOSTIC_EVIDENCE = ['solver_proven', 'input_check', 'heuristic'];

    /**
     * Validate the scheduler response envelope and cross-check its identifiers
     * and counts against the submitted contract request.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $request
     * @return list<string>
     */
    public static function validate(array $response, array $request): array
    {
        $errors = [];

        foreach ([
            'contract_version',
            'generation_id',
            'academic_term_id',
            'snapshot_sha256',
            'solver_status',
            'result_status',
            'started_at',
            'completed_at',
            'solver_execution_ms',
            'optimality_proven',
            'time_limit_reached',
            'assignments',
            'validation_summary',
            'diagnostics',
            'workload_metrics',
            'placement_metrics',
            'warnings',
        ] as $field) {
            if (! array_key_exists($field, $response)) {
                $errors[] = "missing_field:{$field}";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $expectedVersion = (string) config('scheduler.contract_version', '1.0');
        if ($response['contract_version'] !== $expectedVersion) {
            $errors[] = 'contract_version_mismatch';
        }

        $requestMetadata = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];
        foreach (['generation_id', 'snapshot_sha256', 'correlation_id'] as $field) {
            $expected = $requestMetadata[$field] ?? null;
            if ($expected !== null && ($response[$field] ?? null) !== $expected) {
                $errors[] = "response_{$field}_mismatch";
            }
        }

        $expectedTermId = $request['academic_term']['id'] ?? null;
        if ($expectedTermId !== null) {
            $expectedTermId = (string) $expectedTermId;
            if (($response['academic_term_id'] ?? null) !== $expectedTermId) {
                $errors[] = 'response_academic_term_id_mismatch';
            }
        }

        if (! self::isIdentifier($response['academic_term_id'])) {
            $errors[] = 'invalid_academic_term_id';
        }

        if (! in_array($response['solver_status'], self::SOLVER_STATUSES, true)) {
            $errors[] = 'invalid_solver_status';
        }

        if (! in_array($response['result_status'], self::RESULT_STATUSES, true)) {
            $errors[] = 'invalid_result_status';
        }

        if (! self::isUtcTimestamp($response['started_at'])) {
            $errors[] = 'invalid_started_at';
        }

        if (! self::isUtcTimestamp($response['completed_at'])) {
            $errors[] = 'invalid_completed_at';
        }

        if (! self::isNonNegativeInt($response['solver_execution_ms'])) {
            $errors[] = 'invalid_solver_execution_ms';
        }

        foreach (['optimality_proven', 'time_limit_reached'] as $field) {
            if (! is_bool($response[$field])) {
                $errors[] = "invalid_{$field}";
            }
        }

        if (in_array($response['solver_status'], self::SOLVER_STATUSES, true)
            && in_array($response['result_status'], self::RESULT_STATUSES, true)
            && is_bool($response['time_limit_reached'])) {
            $expectedResultStatus = match ($response['solver_status']) {
                'OPTIMAL', 'FEASIBLE' => 'FEASIBLE',
                'INFEASIBLE' => 'INFEASIBLE',
                'UNKNOWN' => $response['time_limit_reached'] ? 'TIME_LIMIT' : 'UNKNOWN',
            };
            if ($response['result_status'] !== $expectedResultStatus) {
                $errors[] = 'result_status_mismatch';
            }
        }

        foreach (['objective_value', 'best_bound'] as $field) {
            if (array_key_exists($field, $response)
                && $response[$field] !== null
                && ! is_int($response[$field])
                && ! is_float($response[$field])) {
                $errors[] = "invalid_{$field}";
            }
        }

        foreach (['assignments', 'diagnostics', 'workload_metrics', 'warnings'] as $field) {
            if (! is_array($response[$field]) || ! array_is_list($response[$field])) {
                $errors[] = "invalid_{$field}";
            }
        }

        if (array_key_exists('unscheduled_mandatory_meeting_ids', $response)
            && $response['unscheduled_mandatory_meeting_ids'] !== null
            && (self::isIdentifierList($response['unscheduled_mandatory_meeting_ids']) === null
                || ! array_is_list($response['unscheduled_mandatory_meeting_ids']))) {
            $errors[] = 'invalid_unscheduled_mandatory_meeting_ids';
        }

        if (! is_array($response['validation_summary'])) {
            $errors[] = 'invalid_validation_summary';
        }

        if (! is_array($response['placement_metrics'])) {
            $errors[] = 'invalid_placement_metrics';
        }

        if (array_key_exists('full_time_distribution', $response)
            && $response['full_time_distribution'] !== null
            && ! is_array($response['full_time_distribution'])) {
            $errors[] = 'invalid_full_time_distribution';
        }

        if ($errors !== []) {
            return $errors;
        }

        $requestIds = self::requestIdentifiers($request);
        $assignments = $response['assignments'];
        $summary = $response['validation_summary'];

        if (! in_array($summary['independent_validation_status'] ?? null, self::VALIDATION_STATUSES, true)) {
            $errors[] = 'invalid_independent_validation_status';
        }

        if (! is_bool($summary['complete_candidate'] ?? null)) {
            $errors[] = 'invalid_complete_candidate';
        }

        if (! is_array($summary['validation_errors'] ?? null) || ! array_is_list($summary['validation_errors'])) {
            $errors[] = 'invalid_validation_errors';
        } else {
            foreach ($summary['validation_errors'] as $validationError) {
                if (! is_string($validationError) || $validationError === '') {
                    $errors[] = 'invalid_validation_error_entry';

                    break;
                }
            }
        }

        foreach ([
            'scheduled_meeting_count',
            'required_mandatory_meeting_count',
            'desired_meeting_count',
            'required_offering_count',
            'represented_offering_count',
        ] as $field) {
            if (! self::isNonNegativeInt($summary[$field] ?? null)) {
                $errors[] = "invalid_summary_{$field}";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($summary['represented_offering_count'] > $summary['required_offering_count']) {
            $errors[] = 'represented_offering_count_exceeds_required';
        }

        if ($summary['required_mandatory_meeting_count'] > $summary['desired_meeting_count']) {
            $errors[] = 'mandatory_meeting_count_exceeds_desired';
        }

        if ($summary['independent_validation_status'] === 'passed' && $summary['validation_errors'] !== []) {
            $errors[] = 'passed_validation_has_errors';
        }

        if ($summary['complete_candidate'] === true) {
            if ($summary['independent_validation_status'] !== 'passed') {
                $errors[] = 'complete_candidate_validation_not_passed';
            }

            if ($summary['represented_offering_count'] !== $summary['required_offering_count']) {
                $errors[] = 'complete_candidate_offerings_not_represented';
            }

            if ($summary['scheduled_meeting_count'] < $summary['required_mandatory_meeting_count']) {
                $errors[] = 'complete_candidate_missing_mandatory_meetings';
            }
        }

        $solverStatus = $response['solver_status'];
        $resultStatus = $response['result_status'];
        $optimalityProven = $response['optimality_proven'];

        if ($solverStatus === 'OPTIMAL' && $response['time_limit_reached'] === true) {
            $errors[] = 'optimal_status_cannot_report_time_limit';
        }

        if ($solverStatus === 'OPTIMAL' && $optimalityProven !== true) {
            $errors[] = 'optimal_status_requires_proof';
        }

        if ($solverStatus !== 'OPTIMAL' && $optimalityProven === true) {
            $errors[] = 'optimality_proven_without_optimal_status';
        }

        if ($resultStatus === 'FEASIBLE') {
            if ($summary['complete_candidate'] !== true) {
                $errors[] = 'feasible_result_not_complete';
            }

            if ($summary['independent_validation_status'] !== 'passed') {
                $errors[] = 'feasible_result_not_validated';
            }
        } elseif ($assignments !== [] || $summary['complete_candidate'] === true) {
            $errors[] = 'non_feasible_result_contains_candidate';
        }

        if (in_array($resultStatus, ['INFEASIBLE', 'UNKNOWN', 'TIME_LIMIT'], true)
            && $summary['independent_validation_status'] !== 'not_run') {
            $errors[] = 'non_feasible_result_validation_not_run';
        }

        if ($summary['scheduled_meeting_count'] !== count($assignments)) {
            $errors[] = 'scheduled_meeting_count_mismatch';
        }

        $meetingIds = [];
        $representedOfferingIds = [];

        foreach ($assignments as $index => $assignment) {
            if (! is_array($assignment)) {
                $errors[] = "assignment_{$index}_not_object";

                continue;
            }

            $meetingId = $assignment['meeting_instance_id'] ?? null;
            if (! self::isIdentifier($meetingId)) {
                $errors[] = "assignment_{$index}_invalid_meeting_instance_id";
            } elseif (isset($meetingIds[$meetingId])) {
                $errors[] = "duplicate_meeting_instance_id:{$meetingId}";
            } else {
                $meetingIds[$meetingId] = true;
            }

            if (! self::isNonNegativeInt($assignment['occurrence_number'] ?? null)
                || $assignment['occurrence_number'] < 1) {
                $errors[] = "assignment_{$index}_invalid_occurrence_number";
            }

            $offeringIds = $assignment['subject_offering_ids'] ?? null;
            if (! self::isIdentifierList($offeringIds) || $offeringIds === []) {
                $errors[] = "assignment_{$index}_invalid_subject_offering_ids";
                $offeringIds = [];
            }

            foreach ($offeringIds as $offeringId) {
                if (! isset($requestIds['offerings'][$offeringId])) {
                    $errors[] = "assignment_{$index}_unknown_offering:{$offeringId}";
                }
                $representedOfferingIds[$offeringId] = true;
            }

            $sectionIds = $assignment['section_ids'] ?? null;
            if (! self::isIdentifierList($sectionIds) || $sectionIds === []) {
                $errors[] = "assignment_{$index}_invalid_section_ids";
                $sectionIds = [];
            }

            foreach ($sectionIds as $sectionId) {
                if (! isset($requestIds['sections'][$sectionId])) {
                    $errors[] = "assignment_{$index}_unknown_section:{$sectionId}";
                }
            }

            $professorId = $assignment['professor_id'] ?? null;
            if (! self::isIdentifier($professorId) || ! isset($requestIds['professors'][$professorId])) {
                $errors[] = "assignment_{$index}_unknown_professor";
            }

            if (! self::isIdentifier($assignment['source_requirement_id'] ?? null)) {
                $errors[] = "assignment_{$index}_invalid_source_requirement_id";
            }

            if (($assignment['is_primary'] ?? null) !== true) {
                $errors[] = "assignment_{$index}_not_primary";
            }

            $roomId = $assignment['room_id'] ?? null;
            if ($roomId !== null && (! self::isIdentifier($roomId) || ! isset($requestIds['rooms'][$roomId]))) {
                $errors[] = "assignment_{$index}_unknown_room";
            }

            if (! in_array($assignment['meeting_type'] ?? null, self::MEETING_TYPES, true)) {
                $errors[] = "assignment_{$index}_invalid_meeting_type";
            }

            if (! in_array($assignment['modality'] ?? null, self::MODALITIES, true)) {
                $errors[] = "assignment_{$index}_invalid_modality";
            }

            $day = $assignment['day_of_week'] ?? null;
            if (! is_int($day) || $day < 1 || $day > 5) {
                $errors[] = "assignment_{$index}_invalid_day_of_week";
            }

            $start = self::clockMinutes($assignment['start_time'] ?? null);
            $end = self::clockMinutes($assignment['end_time'] ?? null);
            $duration = $assignment['duration_minutes'] ?? null;

            if ($start === null || $end === null || $start >= $end) {
                $errors[] = "assignment_{$index}_invalid_time_range";
            } elseif (! self::isNonNegativeInt($duration) || $duration < 1) {
                $errors[] = "assignment_{$index}_invalid_duration";
            } elseif ($end - $start !== $duration) {
                $errors[] = "assignment_{$index}_duration_mismatch";
            }

            $professorAssignments = $assignment['professor_assignments'] ?? null;
            if (! is_array($professorAssignments) || ! array_is_list($professorAssignments) || $professorAssignments === []) {
                $errors[] = "assignment_{$index}_invalid_professor_assignments";

                continue;
            }

            foreach ($professorAssignments as $professorAssignmentIndex => $professorAssignment) {
                if (! is_array($professorAssignment)) {
                    $errors[] = "assignment_{$index}_professor_assignment_{$professorAssignmentIndex}_not_object";

                    continue;
                }

                $assignmentOfferingId = $professorAssignment['subject_offering_id'] ?? null;
                if (! self::isIdentifier($assignmentOfferingId) || ! in_array($assignmentOfferingId, $offeringIds, true)) {
                    $errors[] = "assignment_{$index}_professor_assignment_{$professorAssignmentIndex}_offering_mismatch";
                }

                $facultyAssignmentId = $professorAssignment['faculty_assignment_id'] ?? null;
                $approved = is_string($facultyAssignmentId)
                    ? ($requestIds['professor_assignments'][$facultyAssignmentId] ?? null)
                    : null;

                if (! is_array($approved)) {
                    $errors[] = "assignment_{$index}_professor_assignment_{$professorAssignmentIndex}_unknown_assignment";
                } else {
                    if (($approved['professor_id'] ?? null) !== $professorId) {
                        $errors[] = "assignment_{$index}_professor_assignment_{$professorAssignmentIndex}_professor_mismatch";
                    }

                    if (($approved['subject_offering_id'] ?? null) !== $assignmentOfferingId) {
                        $errors[] = "assignment_{$index}_professor_assignment_{$professorAssignmentIndex}_subject_mismatch";
                    }
                }

                if (! in_array($professorAssignment['assignment_role'] ?? null, self::ASSIGNMENT_ROLES, true)) {
                    $errors[] = "assignment_{$index}_professor_assignment_{$professorAssignmentIndex}_invalid_role";
                }
            }
        }

        if ($summary['represented_offering_count'] !== count($representedOfferingIds)) {
            $errors[] = 'represented_offering_count_mismatch';
        }

        if ($summary['required_offering_count'] !== count($requestIds['offerings'])) {
            $errors[] = 'required_offering_count_mismatch';
        }

        $context = self::requestContext($request);

        self::validateSources($response, $context, $errors);
        self::validateOverlaps($assignments, $errors);
        self::validateWorkload($response, $context, $errors);
        self::validateDiagnostics($response, $errors);
        self::validatePlacementMetrics($response, $errors);
        self::validateFullTimeDistributionShape($response, $errors);

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{
     *     offerings: array<string, array<string, mixed>>,
     *     sections: array<string, array<string, mixed>>,
     *     professors: array<string, array<string, mixed>>,
     *     rooms: array<string, array<string, mixed>>,
     *     requirements: array<string, array<string, mixed>>,
     *     grid: array<int, array<string, mixed>>,
     *     applicable_days: list<int>,
     *     policy: array<string, mixed>|null
     * }
     */
    private static function requestContext(array $request): array
    {
        $context = [
            'offerings' => [],
            'sections' => [],
            'professors' => [],
            'rooms' => [],
            'requirements' => [],
            'grid' => [],
            'applicable_days' => [],
            'policy' => is_array($request['full_time_workday_policy'] ?? null)
                ? $request['full_time_workday_policy']
                : null,
        ];

        foreach (['offerings', 'sections', 'professors', 'rooms'] as $collection) {
            foreach (is_array($request[$collection] ?? null) ? $request[$collection] : [] as $entity) {
                if (is_array($entity) && self::isIdentifier($entity['id'] ?? null)) {
                    $context[$collection][(string) $entity['id']] = $entity;
                }
            }
        }

        $days = $request['time_grid']['days'] ?? null;
        if (is_array($days)) {
            foreach ($days as $day) {
                if (is_array($day) && is_int($day['day_of_week'] ?? null)) {
                    $context['grid'][$day['day_of_week']] = $day;
                }
            }
        }

        $applicableDays = $request['academic_term']['applicable_days'] ?? null;
        if (is_array($applicableDays)) {
            $context['applicable_days'] = array_values(array_map('intval', $applicableDays));
        }

        $assignmentsByOffering = [];
        foreach (is_array($request['professor_assignments'] ?? null) ? $request['professor_assignments'] : [] as $assignment) {
            if (! is_array($assignment) || ! self::isIdentifier($assignment['subject_offering_id'] ?? null)) {
                continue;
            }

            $assignmentsByOffering[(string) $assignment['subject_offering_id']][] = $assignment;
        }

        foreach ($context['offerings'] as $offeringId => $offering) {
            $requirements = $offering['meeting_requirements'] ?? null;

            if (! is_array($requirements)) {
                continue;
            }

            $rows = $assignmentsByOffering[$offeringId] ?? [];

            if (count($rows) !== 1) {
                continue;
            }

            $assignment = $rows[0];
            $sectionId = isset($offering['section_id']) ? (string) $offering['section_id'] : null;

            foreach ($requirements as $requirement) {
                if (! is_array($requirement) || ! self::isIdentifier($requirement['id'] ?? null)) {
                    continue;
                }

                $allowedDays = is_array($requirement['allowed_days'] ?? null)
                    ? array_values(array_map('intval', $requirement['allowed_days']))
                    : $context['applicable_days'];

                $context['requirements'][(string) $requirement['id']] = [
                    'requirement_id' => (string) $requirement['id'],
                    'offering_ids' => [(string) $offeringId],
                    'section_ids' => $sectionId === null ? [] : [$sectionId],
                    'professor_id' => (string) ($assignment['professor_id'] ?? ''),
                    'faculty_assignments' => [[
                        (string) ($assignment['subject_offering_id'] ?? $offeringId),
                        (string) ($assignment['id'] ?? ''),
                        (string) ($assignment['assignment_role'] ?? ''),
                    ]],
                    'meeting_type' => $requirement['meeting_type'] ?? null,
                    'modality' => $requirement['modality'] ?? null,
                    'duration_minutes' => $requirement['duration_minutes'] ?? null,
                    'frequency_per_week' => $requirement['frequency_per_week'] ?? null,
                    'mandatory' => $requirement['mandatory'] ?? null,
                    'allowed_days' => $allowedDays,
                    'valid_start_times' => is_array($requirement['valid_start_times'] ?? null)
                        ? array_values($requirement['valid_start_times'])
                        : [],
                    'required_room_type' => $requirement['required_room_type'] ?? null,
                ];
            }
        }

        $consumedPairs = [];

        foreach (is_array($request['combined_classes'] ?? null) ? $request['combined_classes'] : [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            $offeringIds = is_array($group['offering_ids'] ?? null)
                ? array_values(array_map('strval', $group['offering_ids']))
                : [];

            $sharedRequirements = $group['shared_meeting_requirements'] ?? null;

            if ($offeringIds === [] || ! is_array($sharedRequirements)) {
                continue;
            }

            $professorIds = [];
            $sectionIds = [];
            $faculty = [];

            foreach ($offeringIds as $offeringId) {
                $offering = $context['offerings'][$offeringId] ?? null;
                $rows = $assignmentsByOffering[$offeringId] ?? [];

                if (! is_array($offering) || count($rows) !== 1) {
                    $professorIds = [];

                    break;
                }

                $professorIds[] = (string) ($rows[0]['professor_id'] ?? '');
                $sectionIds[] = (string) ($offering['section_id'] ?? '');
                $faculty[] = [
                    (string) ($rows[0]['subject_offering_id'] ?? $offeringId),
                    (string) ($rows[0]['id'] ?? ''),
                    (string) ($rows[0]['assignment_role'] ?? ''),
                ];
            }

            if ($professorIds === [] || count(array_unique($professorIds)) !== 1) {
                continue;
            }

            sort($sectionIds);
            self::sortTriples($faculty);

            foreach ($sharedRequirements as $shared) {
                if (! is_array($shared) || ! self::isIdentifier($shared['id'] ?? null)) {
                    continue;
                }

                $sourceRequirements = $shared['source_requirements'] ?? null;

                if (! is_array($sourceRequirements) || count($sourceRequirements) !== count($offeringIds)) {
                    continue;
                }

                $first = null;
                $valid = true;

                foreach ($sourceRequirements as $source) {
                    if (! is_array($source)) {
                        $valid = false;

                        break;
                    }

                    $offeringId = $source['offering_id'] ?? null;
                    $requirementId = $source['meeting_requirement_id'] ?? null;

                    if (! self::isIdentifier($offeringId) || ! self::isIdentifier($requirementId)) {
                        $valid = false;

                        break;
                    }

                    $requirement = $context['requirements'][(string) $requirementId] ?? null;

                    if (! is_array($requirement)
                        || ($requirement['offering_ids'][0] ?? null) !== (string) $offeringId
                        || isset($consumedPairs[(string) $offeringId.'|'.(string) $requirementId])) {
                        $valid = false;

                        break;
                    }

                    $consumedPairs[(string) $offeringId.'|'.(string) $requirementId] = true;

                    if ($first === null) {
                        $first = $requirement;

                        continue;
                    }

                    foreach ([
                        'meeting_type',
                        'frequency_per_week',
                        'duration_minutes',
                        'modality',
                        'required_room_type',
                        'mandatory',
                        'allowed_days',
                        'valid_start_times',
                    ] as $key) {
                        if (($requirement[$key] ?? null) !== ($first[$key] ?? null)) {
                            $valid = false;

                            break 2;
                        }
                    }
                }

                if (! $valid || $first === null) {
                    continue;
                }

                $sortedOfferings = $offeringIds;
                sort($sortedOfferings);

                $context['requirements'][(string) $shared['id']] = [
                    'requirement_id' => (string) $shared['id'],
                    'offering_ids' => $sortedOfferings,
                    'section_ids' => $sectionIds,
                    'professor_id' => $professorIds[0],
                    'faculty_assignments' => $faculty,
                    'meeting_type' => $first['meeting_type'],
                    'modality' => $first['modality'],
                    'duration_minutes' => $first['duration_minutes'],
                    'frequency_per_week' => $first['frequency_per_week'],
                    'mandatory' => $first['mandatory'],
                    'allowed_days' => $first['allowed_days'],
                    'valid_start_times' => $first['valid_start_times'],
                    'required_room_type' => $first['required_room_type'],
                ];
            }
        }

        foreach (array_keys($consumedPairs) as $pair) {
            [, $requirementId] = explode('|', (string) $pair, 2);

            if (isset($context['requirements'][$requirementId])
                && ! isset($context['requirements'][$requirementId]['is_shared'])) {
                unset($context['requirements'][$requirementId]);
            }
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @param  list<string>  $errors
     */
    private static function validateSources(array $response, array $context, array &$errors): void
    {
        if ($context['requirements'] === []) {
            return;
        }

        $sources = [];
        $requiredIds = [];
        $desiredIds = [];

        foreach ($context['requirements'] as $requirement) {
            $frequency = $requirement['frequency_per_week'];

            if (! is_int($frequency) || $frequency < 1) {
                continue;
            }

            for ($occurrence = 1; $occurrence <= $frequency; $occurrence++) {
                $instanceId = $requirement['requirement_id'].':'.$occurrence;
                $sources[$instanceId] = $requirement + ['occurrence' => $occurrence];
                $desiredIds[$instanceId] = true;

                if ($requirement['mandatory'] === true) {
                    $requiredIds[$instanceId] = true;
                }
            }
        }

        $assignmentMap = [];

        foreach ($response['assignments'] as $assignment) {
            if (is_array($assignment) && self::isIdentifier($assignment['meeting_instance_id'] ?? null)) {
                $assignmentMap[$assignment['meeting_instance_id']] = $assignment;
            }
        }

        foreach ($requiredIds as $instanceId => $_) {
            if (! isset($assignmentMap[$instanceId])) {
                $errors[] = "missing_mandatory_meeting:{$instanceId}";
            }
        }

        foreach ($assignmentMap as $instanceId => $_) {
            if (! isset($desiredIds[$instanceId])) {
                $errors[] = "unknown_meeting_instance:{$instanceId}";
            }
        }

        if (($response['validation_summary']['required_mandatory_meeting_count'] ?? null) !== count($requiredIds)) {
            $errors[] = 'required_mandatory_meeting_count_mismatch';
        }

        if (($response['validation_summary']['desired_meeting_count'] ?? null) !== count($desiredIds)) {
            $errors[] = 'desired_meeting_count_mismatch';
        }

        foreach ($assignmentMap as $instanceId => $assignment) {
            $source = $sources[$instanceId] ?? null;

            if ($source === null) {
                continue;
            }

            $path = "assignment[{$instanceId}]";

            if (($assignment['source_requirement_id'] ?? null) !== $source['requirement_id']) {
                $errors[] = "{$path}_source_requirement_mismatch";
            }

            if (($assignment['occurrence_number'] ?? null) !== $source['occurrence']) {
                $errors[] = "{$path}_occurrence_mismatch";
            }

            $offeringIds = is_array($assignment['subject_offering_ids'] ?? null)
                ? array_values(array_map('strval', $assignment['subject_offering_ids']))
                : [];
            sort($offeringIds);
            $expectedOfferingIds = $source['offering_ids'];
            sort($expectedOfferingIds);

            if ($offeringIds !== $expectedOfferingIds) {
                $errors[] = "{$path}_offering_mismatch";
            }

            $sectionIds = is_array($assignment['section_ids'] ?? null)
                ? array_values(array_map('strval', $assignment['section_ids']))
                : [];
            sort($sectionIds);
            $expectedSectionIds = $source['section_ids'];
            sort($expectedSectionIds);

            if ($sectionIds !== $expectedSectionIds) {
                $errors[] = "{$path}_section_mismatch";
            }

            if (($assignment['professor_id'] ?? null) !== $source['professor_id']) {
                $errors[] = "{$path}_professor_mismatch";
            }

            $actualFaculty = [];

            foreach (is_array($assignment['professor_assignments'] ?? null) ? $assignment['professor_assignments'] : [] as $professorAssignment) {
                if (is_array($professorAssignment)) {
                    $actualFaculty[] = [
                        (string) ($professorAssignment['subject_offering_id'] ?? ''),
                        (string) ($professorAssignment['faculty_assignment_id'] ?? ''),
                        (string) ($professorAssignment['assignment_role'] ?? ''),
                    ];
                }
            }

            self::sortTriples($actualFaculty);
            $expectedFaculty = $source['faculty_assignments'];
            self::sortTriples($expectedFaculty);

            if ($actualFaculty !== $expectedFaculty) {
                $errors[] = "{$path}_faculty_mismatch";
            }

            if (($assignment['meeting_type'] ?? null) !== $source['meeting_type']
                || ($assignment['modality'] ?? null) !== $source['modality']) {
                $errors[] = "{$path}_meeting_requirement_mismatch";
            }

            if (($assignment['duration_minutes'] ?? null) !== $source['duration_minutes']) {
                $errors[] = "{$path}_requirement_duration_mismatch";
            }

            $start = self::clockMinutes($assignment['start_time'] ?? null);
            $end = self::clockMinutes($assignment['end_time'] ?? null);
            $day = $assignment['day_of_week'] ?? null;

            if ($start !== null && $end !== null && is_int($source['duration_minutes'])
                && $end - $start !== $source['duration_minutes']) {
                $errors[] = "{$path}_interval_duration_mismatch";
            }

            if (! is_int($day) || ! in_array($day, $source['allowed_days'], true)) {
                $errors[] = "{$path}_day_not_allowed";
            }

            $grid = is_int($day) ? ($context['grid'][$day] ?? null) : null;

            if ($grid === null) {
                if ($context['grid'] !== []) {
                    $errors[] = "{$path}_day_not_in_time_grid";
                }
            } elseif ($start !== null && $end !== null) {
                $validStarts = $source['valid_start_times'] !== []
                    ? $source['valid_start_times']
                    : (is_array($grid['valid_start_times'] ?? null) ? $grid['valid_start_times'] : []);
                $opening = self::clockMinutes($grid['operating_start'] ?? null);
                $closing = self::clockMinutes($grid['operating_end'] ?? null);

                if (! in_array($assignment['start_time'] ?? null, $validStarts, true)
                    || ($opening !== null && $start < $opening)
                    || ($closing !== null && $end > $closing)) {
                    $errors[] = "{$path}_time_grid_violation";
                }
            }

            $professor = $context['professors'][$source['professor_id']] ?? null;

            if (is_array($professor) && $start !== null && $end !== null && is_int($day)) {
                $windows = is_array($professor['availability_windows'] ?? null)
                    ? $professor['availability_windows']
                    : [];

                if (self::availabilityError($start, $end, $day, $windows)) {
                    $errors[] = "{$path}_professor_availability_violation";
                }

                if (($professor['employment_type'] ?? null) === 'full_time' && is_array($context['policy'])) {
                    $allowedDays = is_array($context['policy']['standard_days'] ?? null)
                        ? array_values(array_map('intval', $context['policy']['standard_days']))
                        : [];

                    foreach (is_array($context['policy']['exceptions'] ?? null) ? $context['policy']['exceptions'] : [] as $exception) {
                        if (is_array($exception) && ($exception['professor_id'] ?? null) === $source['professor_id']) {
                            $additional = is_array($exception['authorized_additional_days'] ?? null)
                                ? array_map('intval', $exception['authorized_additional_days'])
                                : [];
                            $allowedDays = array_merge($allowedDays, $additional);
                        }
                    }

                    if (! in_array($day, $allowedDays, true)) {
                        $errors[] = "{$path}_full_time_workday_violation";
                    }
                }
            }

            foreach ($source['section_ids'] as $sectionId) {
                $section = $context['sections'][$sectionId] ?? null;
                $windows = is_array($section['availability_windows'] ?? null)
                    ? $section['availability_windows']
                    : [];

                if ($windows !== [] && $start !== null && $end !== null && is_int($day)
                    && ! self::fitsWindow($start, $end, $windows, $day)) {
                    $errors[] = "{$path}_section_availability_violation";
                }
            }

            if (($assignment['modality'] ?? null) === 'online') {
                if (($assignment['room_id'] ?? null) !== null) {
                    $errors[] = "{$path}_online_room_not_allowed";
                }

                continue;
            }

            $roomId = $assignment['room_id'] ?? null;
            $room = self::isIdentifier($roomId) ? ($context['rooms'][$roomId] ?? null) : null;

            if (! is_array($room)) {
                $errors[] = "{$path}_unknown_or_unavailable_room";

                continue;
            }

            $enrollment = 0;

            foreach ($source['offering_ids'] as $offeringId) {
                $offering = $context['offerings'][$offeringId] ?? null;

                if (is_array($offering)) {
                    $enrollment += (int) ($offering['expected_enrollment'] ?? 0);
                }
            }

            if (isset($room['capacity']) && is_int($room['capacity']) && $room['capacity'] < $enrollment) {
                $errors[] = "{$path}_room_capacity_too_small";
            }

            if ($source['required_room_type'] !== null
                && ($room['room_type'] ?? null) !== $source['required_room_type']) {
                $errors[] = "{$path}_room_type_mismatch";
            }
        }
    }

    /**
     * @param  list<array<string, mixed>|mixed>  $assignments
     * @param  list<string>  $errors
     */
    private static function validateOverlaps(array $assignments, array &$errors): void
    {
        $count = count($assignments);

        for ($firstIndex = 0; $firstIndex < $count; $firstIndex++) {
            $first = $assignments[$firstIndex];

            if (! is_array($first)) {
                continue;
            }

            $firstDay = $first['day_of_week'] ?? null;
            $firstStart = self::clockMinutes($first['start_time'] ?? null);
            $firstEnd = self::clockMinutes($first['end_time'] ?? null);

            if (! is_int($firstDay) || $firstStart === null || $firstEnd === null) {
                continue;
            }

            for ($secondIndex = $firstIndex + 1; $secondIndex < $count; $secondIndex++) {
                $second = $assignments[$secondIndex];

                if (! is_array($second) || ($second['day_of_week'] ?? null) !== $firstDay) {
                    continue;
                }

                $secondStart = self::clockMinutes($second['start_time'] ?? null);
                $secondEnd = self::clockMinutes($second['end_time'] ?? null);

                if ($secondStart === null || $secondEnd === null) {
                    continue;
                }

                if (! ($firstStart < $secondEnd && $secondStart < $firstEnd)) {
                    continue;
                }

                if (($first['professor_id'] ?? null) !== null
                    && ($first['professor_id'] ?? null) === ($second['professor_id'] ?? null)) {
                    $errors[] = 'professor_overlap';
                }

                $firstSections = is_array($first['section_ids'] ?? null) ? $first['section_ids'] : [];
                $secondSections = is_array($second['section_ids'] ?? null) ? $second['section_ids'] : [];

                if (array_intersect($firstSections, $secondSections) !== []) {
                    $errors[] = 'section_overlap';
                }

                if (($first['room_id'] ?? null) !== null
                    && ($first['room_id'] ?? null) === ($second['room_id'] ?? null)) {
                    $errors[] = 'room_overlap';
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @param  list<string>  $errors
     */
    private static function validateWorkload(array $response, array $context, array &$errors): void
    {
        if ($context['professors'] === []) {
            return;
        }

        $hasEmploymentData = false;

        foreach ($context['professors'] as $professor) {
            if (isset($professor['employment_type'])) {
                $hasEmploymentData = true;

                break;
            }
        }

        if (! $hasEmploymentData) {
            return;
        }

        $expectedMinutes = [];

        foreach ($context['professors'] as $professorId => $_) {
            $expectedMinutes[(string) $professorId] = 0;
        }

        foreach ($response['assignments'] as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $professorId = $assignment['professor_id'] ?? null;

            if (self::isIdentifier($professorId)) {
                $expectedMinutes[$professorId] = ($expectedMinutes[$professorId] ?? 0)
                    + (int) ($assignment['duration_minutes'] ?? 0);
            }
        }

        $metrics = [];

        foreach ($response['workload_metrics'] as $metric) {
            if (is_array($metric) && self::isIdentifier($metric['professor_id'] ?? null)) {
                $metrics[$metric['professor_id']] = $metric;
            }
        }

        if (count($metrics) !== count($expectedMinutes)) {
            $errors[] = 'workload_metrics_professor_coverage_mismatch';
        }

        foreach ($expectedMinutes as $professorId => $minutes) {
            $metric = $metrics[$professorId] ?? null;
            $professor = $context['professors'][$professorId] ?? null;

            if (! is_array($metric)) {
                $errors[] = "workload_metrics_{$professorId}_missing";

                continue;
            }

            if (is_array($professor)
                && isset($professor['employment_type'])
                && ($metric['employment_type'] ?? null) !== $professor['employment_type']) {
                $errors[] = "workload_metrics_{$professorId}_employment_type_mismatch";
            }

            if (($metric['assigned_teaching_minutes'] ?? null) !== $minutes) {
                $errors[] = "workload_metrics_{$professorId}_assigned_minutes_mismatch";
            }

            $target = is_array($professor) ? ($professor['part_time_weekly_target_minutes'] ?? null) : null;

            if (array_key_exists('weekly_target_minutes', $metric)
                && $metric['weekly_target_minutes'] !== $target) {
                $errors[] = "workload_metrics_{$professorId}_weekly_target_mismatch";
            }
        }

        $fullTimeMinutes = [];

        foreach ($expectedMinutes as $professorId => $minutes) {
            if (($context['professors'][$professorId]['employment_type'] ?? null) === 'full_time') {
                $fullTimeMinutes[] = $minutes;
            }
        }

        $distribution = $response['full_time_distribution'] ?? null;

        if ($fullTimeMinutes === []) {
            if ($distribution !== null) {
                $errors[] = 'full_time_distribution_not_applicable';
            }

            return;
        }

        if (! is_array($distribution)) {
            $errors[] = 'full_time_distribution_required';

            return;
        }

        sort($fullTimeMinutes);
        $minimum = $fullTimeMinutes[0];
        $maximum = $fullTimeMinutes[count($fullTimeMinutes) - 1];

        if (($distribution['minimum_assigned_minutes'] ?? null) !== $minimum
            || ($distribution['maximum_assigned_minutes'] ?? null) !== $maximum
            || ($distribution['spread_minutes'] ?? null) !== $maximum - $minimum) {
            $errors[] = 'full_time_distribution_mismatch';
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $errors
     */
    private static function validateDiagnostics(array $response, array &$errors): void
    {
        foreach ($response['diagnostics'] as $index => $diagnostic) {
            if (! is_array($diagnostic)) {
                $errors[] = "diagnostic_{$index}_not_object";

                continue;
            }

            if (! self::isIdentifier($diagnostic['code'] ?? null)) {
                $errors[] = "diagnostic_{$index}_invalid_code";
            }

            if (! is_string($diagnostic['message'] ?? null) || ($diagnostic['message'] ?? '') === '') {
                $errors[] = "diagnostic_{$index}_invalid_message";
            }

            if (! in_array($diagnostic['evidence'] ?? null, self::DIAGNOSTIC_EVIDENCE, true)) {
                $errors[] = "diagnostic_{$index}_invalid_evidence";
            }

            if (self::isIdentifierList($diagnostic['meeting_requirement_ids'] ?? null) === null
                || ! array_is_list($diagnostic['meeting_requirement_ids'] ?? null)) {
                $errors[] = "diagnostic_{$index}_invalid_meeting_requirement_ids";
            }

            if (self::isIdentifierList($diagnostic['related_entity_ids'] ?? null) === null
                || ! array_is_list($diagnostic['related_entity_ids'] ?? null)) {
                $errors[] = "diagnostic_{$index}_invalid_related_entity_ids";
            }
        }

        foreach ($response['warnings'] as $index => $warning) {
            if (! is_array($warning)) {
                $errors[] = "warning_{$index}_not_object";

                continue;
            }

            if (! self::isIdentifier($warning['code'] ?? null)) {
                $errors[] = "warning_{$index}_invalid_code";
            }

            if (! is_string($warning['message'] ?? null) || ($warning['message'] ?? '') === '') {
                $errors[] = "warning_{$index}_invalid_message";
            }

            if (array_key_exists('related_entity_ids', $warning)
                && $warning['related_entity_ids'] !== null
                && (self::isIdentifierList($warning['related_entity_ids']) === null
                    || ! array_is_list($warning['related_entity_ids']))) {
                $errors[] = "warning_{$index}_invalid_related_entity_ids";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $errors
     */
    private static function validatePlacementMetrics(array $response, array &$errors): void
    {
        $metrics = $response['placement_metrics'];

        foreach ([
            'comparable_meeting_count',
            'retained_placement_count',
            'changed_placement_count',
            'unmatched_baseline_schedule_count',
        ] as $field) {
            if (! self::isNonNegativeInt($metrics[$field] ?? null)) {
                $errors[] = "invalid_placement_metrics_{$field}";
            }
        }

        if ($errors !== [] && ! self::hasPrefix($errors, 'invalid_placement_metrics_')) {
            return;
        }

        $comparable = $metrics['comparable_meeting_count'] ?? null;
        $retained = $metrics['retained_placement_count'] ?? null;
        $changed = $metrics['changed_placement_count'] ?? null;

        if (is_int($comparable) && is_int($retained) && is_int($changed)
            && $retained + $changed !== $comparable) {
            $errors[] = 'placement_metrics_count_mismatch';
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $errors
     */
    private static function validateFullTimeDistributionShape(array $response, array &$errors): void
    {
        $distribution = $response['full_time_distribution'] ?? null;

        if ($distribution === null) {
            return;
        }

        if (! is_array($distribution)) {
            return;
        }

        foreach ([
            'minimum_assigned_minutes',
            'maximum_assigned_minutes',
            'spread_minutes',
        ] as $field) {
            if (! self::isNonNegativeInt($distribution[$field] ?? null)) {
                $errors[] = "invalid_full_time_distribution_{$field}";
            }
        }

        $minimum = $distribution['minimum_assigned_minutes'] ?? null;
        $maximum = $distribution['maximum_assigned_minutes'] ?? null;
        $spread = $distribution['spread_minutes'] ?? null;

        if (is_int($minimum) && is_int($maximum) && is_int($spread)
            && ($maximum < $minimum || $spread !== $maximum - $minimum)) {
            $errors[] = 'full_time_distribution_shape_mismatch';
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function hasPrefix(array $errors, string $prefix): bool
    {
        foreach ($errors as $error) {
            if (str_starts_with($error, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{
     *     offerings: array<string, true>,
     *     included_offerings: array<string, true>,
     *     sections: array<string, true>,
     *     professors: array<string, true>,
     *     rooms: array<string, true>,
     *     professor_assignments: array<string, array<string, mixed>>
     * }
     */
    private static function requestIdentifiers(array $request): array
    {
        $identifiers = [
            'offerings' => [],
            'included_offerings' => [],
            'sections' => [],
            'professors' => [],
            'rooms' => [],
            'professor_assignments' => [],
        ];

        foreach (['offerings', 'sections', 'professors', 'rooms'] as $collection) {
            foreach (is_array($request[$collection] ?? null) ? $request[$collection] : [] as $entity) {
                if (is_array($entity) && self::isIdentifier($entity['id'] ?? null)) {
                    $identifiers[$collection][$entity['id']] = true;
                }
            }
        }

        $scope = is_array($request['generation_scope'] ?? null) ? $request['generation_scope'] : [];
        foreach (is_array($scope['included_offering_ids'] ?? null) ? $scope['included_offering_ids'] : [] as $offeringId) {
            if (self::isIdentifier($offeringId)) {
                $identifiers['included_offerings'][$offeringId] = true;
            }
        }

        foreach (is_array($request['professor_assignments'] ?? null) ? $request['professor_assignments'] : [] as $assignment) {
            if (is_array($assignment) && self::isIdentifier($assignment['id'] ?? null)) {
                $identifiers['professor_assignments'][$assignment['id']] = $assignment;
            }
        }

        return $identifiers;
    }

    private static function isIdentifier(mixed $value): bool
    {
        return is_string($value) && $value !== '' && preg_match('/\s/', $value) !== 1;
    }

    /**
     * @return list<string>|null
     */
    private static function isIdentifierList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $item) {
            if (! self::isIdentifier($item)) {
                return null;
            }
        }

        return $value;
    }

    private static function isNonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function isUtcTimestamp(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function clockMinutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $value));

        return ($hours * 60) + $minutes;
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     */
    private static function fitsWindow(int $start, int $end, array $windows, int $day): bool
    {
        foreach ($windows as $window) {
            if (! is_array($window) || ($window['day_of_week'] ?? null) !== $day) {
                continue;
            }

            $windowStart = self::clockMinutes($window['start_time'] ?? null);
            $windowEnd = self::clockMinutes($window['end_time'] ?? null);

            if ($windowStart !== null && $windowEnd !== null
                && $windowStart <= $start && $end <= $windowEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $windows
     */
    private static function availabilityError(int $start, int $end, int $day, array $windows): bool
    {
        $positive = [];

        foreach ($windows as $window) {
            if (! is_array($window) || ($window['day_of_week'] ?? null) !== $day) {
                continue;
            }

            $windowStart = self::clockMinutes($window['start_time'] ?? null);
            $windowEnd = self::clockMinutes($window['end_time'] ?? null);

            if ($windowStart === null || $windowEnd === null) {
                continue;
            }

            if (($window['availability_type'] ?? null) === 'unavailable'
                && $start < $windowEnd && $windowStart < $end) {
                return true;
            }

            if (in_array($window['availability_type'] ?? null, ['available', 'preferred'], true)) {
                $positive[] = $window;
            }
        }

        return $positive !== [] && ! self::fitsWindow($start, $end, $positive, $day);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $triples
     */
    private static function sortTriples(array &$triples): void
    {
        usort($triples, fn (array $left, array $right): int => [$left[0], $left[1], $left[2]] <=> [$right[0], $right[1], $right[2]]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\CandidateMeetingOffering;
use App\Models\CandidateMeetingPlacement;
use App\Models\CandidateMeetingProfessor;
use App\Models\CandidateRoomAllocation;
use App\Models\CandidateStatusEvent;
use App\Models\FacultyAssignment;
use App\Models\Professor;
use App\Models\Room;
use App\Models\SchedulingCandidate;
use App\Models\SchedulingGeneration;
use App\Models\SchedulingMeetingRequirement;
use App\Models\SchedulingSnapshot;
use App\Models\SubjectOffering;
use App\Support\SchedulerResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SchedulingGenerationService
{
    public function start(AcademicTerm $term, ?Authenticatable $user, array $payload): SchedulingGeneration
    {
        return DB::transaction(function () use ($term, $user, $payload): SchedulingGeneration {
            $lockedTerm = AcademicTerm::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();
            $canonical = SnapshotHasher::canonicalJson(Arr::except($payload, ['contract_version', 'metadata']));
            $hash = hash('sha256', $canonical);
            $declaredHash = (string) Arr::get($payload, 'metadata.snapshot_sha256', '');

            if (! hash_equals($declaredHash, $hash)) {
                throw new RuntimeException('The scheduling request snapshot hash does not match its canonical content.');
            }

            $snapshot = SchedulingSnapshot::query()->where('content_sha256', $hash)->first();

            if ($snapshot === null) {
                $version = (int) SchedulingSnapshot::query()
                    ->where('academic_term_id', $lockedTerm->id)
                    ->max('version') + 1;

                $snapshot = SchedulingSnapshot::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'academic_term_id' => $lockedTerm->id,
                    'version' => $version,
                    'contract_version' => (string) Arr::get($payload, 'contract_version', '1.0'),
                    'content_canonical' => $canonical,
                    'content_sha256' => $hash,
                    'created_by_user_id' => $this->userId($user),
                    'created_at' => now(),
                ]);
            }

            $generationId = (string) Arr::get($payload, 'metadata.generation_id');
            if ($generationId === '') {
                throw new RuntimeException('The scheduling request does not contain a generation identifier.');
            }

            return SchedulingGeneration::query()->create([
                'uuid' => $generationId,
                'academic_term_id' => $lockedTerm->id,
                'scheduling_snapshot_id' => $snapshot->id,
                'status' => 'running',
                'generation_scope' => is_array($payload['generation_scope'] ?? null) ? $payload['generation_scope'] : [],
                'solver_config' => is_array($payload['solver_config'] ?? null) ? $payload['solver_config'] : [],
                'contract_version' => (string) Arr::get($payload, 'contract_version', '1.0'),
                'requested_by_user_id' => $this->userId($user),
                'requested_at' => now(),
                'started_at' => now(),
                'correlation_id' => Arr::get($payload, 'metadata.correlation_id'),
            ]);
        });
    }

    public function recordFailure(SchedulingGeneration $generation, SchedulerResponse $response): void
    {
        $body = $response->getBody();
        $details = is_array($body['details'] ?? null) ? $body['details'] : null;

        $this->recordGenerationFailure(
            $generation,
            [
                'response_payload' => $body,
                'diagnostics' => $details,
            ],
            $response->getErrorCode() ?? 'scheduler_error',
            $response->getMessage(),
        );
    }

    public function recordOutcome(SchedulingGeneration $generation, array $payload, array $result): ?SchedulingCandidate
    {
        $summary = is_array($result['validation_summary'] ?? null) ? $result['validation_summary'] : [];
        $validationStatus = match ($summary['independent_validation_status'] ?? null) {
            'passed' => 'valid',
            'failed' => 'invalid',
            default => 'not_validated',
        };
        $sourceResponseHash = hash('sha256', SnapshotHasher::canonicalJson($result));
        $attributes = [
            'status' => 'completed',
            'solver_status' => strtolower((string) ($result['solver_status'] ?? '')),
            'result_status' => strtolower((string) ($result['result_status'] ?? '')),
            'solver_config' => is_array($payload['solver_config'] ?? null) ? $payload['solver_config'] : [],
            'solver_execution_ms' => $this->nonNegativeInt($result['solver_execution_ms'] ?? null),
            'objective_value' => is_numeric($result['objective_value'] ?? null)
                ? (float) $result['objective_value']
                : null,
            'best_bound' => is_numeric($result['best_bound'] ?? null)
                ? (float) $result['best_bound']
                : null,
            'optimality_proven' => is_bool($result['optimality_proven'] ?? null)
                ? $result['optimality_proven']
                : null,
            'time_limit_reached' => is_bool($result['time_limit_reached'] ?? null)
                ? $result['time_limit_reached']
                : null,
            'unscheduled_mandatory_meeting_ids' => is_array($result['unscheduled_mandatory_meeting_ids'] ?? null)
                ? $result['unscheduled_mandatory_meeting_ids']
                : null,
            'response_payload' => $result,
            'started_at' => $this->timestamp($result['started_at'] ?? null),
            'completed_at' => $this->timestamp($result['completed_at'] ?? null) ?? now(),
            'failure_code' => null,
            'failure_message' => null,
            'diagnostics' => is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : null,
            'input_metrics' => $this->inputMetrics($payload),
            'workload_metrics' => is_array($result['workload_metrics'] ?? null) ? $result['workload_metrics'] : null,
            'placement_metrics' => is_array($result['placement_metrics'] ?? null) ? $result['placement_metrics'] : null,
        ];

        try {
            return DB::transaction(function () use ($generation, $payload, $result, $attributes, $sourceResponseHash, $validationStatus, $summary): ?SchedulingCandidate {
                $locked = $this->lockRunningGeneration($generation);
                if ($locked === null) {
                    Log::warning('scheduling.generation.stale_outcome', [
                        'generation_id' => $generation->uuid,
                    ]);

                    return null;
                }

                AcademicTerm::query()->whereKey($locked->academic_term_id)->lockForUpdate()->firstOrFail();

                if (! $this->candidateResponseIsComplete($result)) {
                    $locked->forceFill([
                        ...$attributes,
                        'status' => 'failed',
                        'failure_code' => 'solver_candidate_incomplete',
                        'failure_message' => 'The solver response does not contain a complete validated candidate.',
                        'diagnostics' => [$summary],
                    ])->save();

                    return null;
                }

                $referenceErrors = $this->validateReferences($locked, $payload, $result);
                if ($referenceErrors !== []) {
                    Log::warning('scheduling.generation.reference_validation_failed', [
                        'generation_id' => $generation->uuid,
                        'error_count' => count($referenceErrors),
                    ]);

                    $locked->forceFill([
                        ...$attributes,
                        'status' => 'failed',
                        'failure_code' => 'solver_reference_validation_failed',
                        'failure_message' => 'The solver response references records that are no longer authoritative.',
                        'diagnostics' => $referenceErrors,
                    ])->save();

                    return null;
                }

                $candidate = $this->persistCandidate($locked, $payload, $result, $validationStatus, $sourceResponseHash);
                $locked->forceFill($attributes)->save();

                return $candidate;
            });
        } catch (Throwable $exception) {
            Log::warning('scheduling.generation.candidate_persistence_failed', [
                'generation_id' => $generation->uuid,
                'exception' => $exception->getMessage(),
            ]);

            $this->recordGenerationFailure(
                $generation,
                $attributes,
                'candidate_persistence_failed',
                'The solver response could not be persisted as a candidate.',
                [['message' => $exception->getMessage()]],
            );

            return null;
        }
    }

    private function lockRunningGeneration(SchedulingGeneration $generation): ?SchedulingGeneration
    {
        return SchedulingGeneration::query()
            ->whereKey($generation->id)
            ->whereIn('status', ['queued', 'running'])
            ->lockForUpdate()
            ->first();
    }

    private function recordGenerationFailure(
        SchedulingGeneration $generation,
        array $attributes,
        string $failureCode,
        string $failureMessage,
        ?array $diagnostics = null,
    ): void {
        DB::transaction(function () use ($generation, $attributes, $failureCode, $failureMessage, $diagnostics): void {
            $locked = $this->lockRunningGeneration($generation);
            if ($locked === null) {
                return;
            }

            $locked->forceFill([
                ...$attributes,
                'status' => 'failed',
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'diagnostics' => $diagnostics ?? ($attributes['diagnostics'] ?? null),
                'completed_at' => now(),
            ])->save();
        });
    }

    private function candidateResponseIsComplete(array $result): bool
    {
        $summary = is_array($result['validation_summary'] ?? null) ? $result['validation_summary'] : [];

        return ($result['result_status'] ?? null) === 'FEASIBLE'
            && ($summary['complete_candidate'] ?? null) === true
            && ($summary['independent_validation_status'] ?? null) === 'passed';
    }

    public function generationSummary(SchedulingGeneration $generation): array
    {
        return [
            'uuid' => $generation->uuid,
            'status' => $generation->status,
            'solver_status' => $generation->solver_status,
            'result_status' => $generation->result_status,
            'failure_code' => $generation->failure_code,
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }

    public function candidateSummary(?SchedulingCandidate $candidate): ?array
    {
        if ($candidate === null) {
            return null;
        }

        return [
            'uuid' => $candidate->uuid,
            'version' => $candidate->version,
            'status' => $candidate->status,
            'validation_status' => $candidate->validation_status,
            'assignment_count' => $candidate->placements()->count(),
        ];
    }

    private function validateReferences(SchedulingGeneration $generation, array $payload, array $result): array
    {
        $errors = [];
        $assignments = is_array($result['assignments'] ?? null) ? $result['assignments'] : [];
        $requestOfferingIds = $this->identifierSet($this->column($payload['offerings'] ?? [], 'id'));
        $requestRoomIds = $this->identifierSet($this->column($payload['rooms'] ?? [], 'id'));
        $requestProfessorIds = $this->identifierSet($this->column($payload['professors'] ?? [], 'id'));
        $requestRequirementIds = [];
        $requestFacultyAssignments = [];

        foreach (is_array($payload['offerings'] ?? null) ? $payload['offerings'] : [] as $offering) {
            if (! is_array($offering) || ! is_array($offering['meeting_requirements'] ?? null)) {
                continue;
            }

            foreach ($offering['meeting_requirements'] as $requirement) {
                if (is_array($requirement) && is_string($requirement['id'] ?? null)) {
                    $requestRequirementIds[$requirement['id']] = true;
                }
            }
        }

        foreach (is_array($payload['professor_assignments'] ?? null) ? $payload['professor_assignments'] : [] as $assignment) {
            if (is_array($assignment) && is_string($assignment['id'] ?? null)) {
                $requestFacultyAssignments[$assignment['id']] = $assignment;
            }
        }

        $offeringIds = [];
        $requirementIds = [];
        $professorIds = [];
        $facultyAssignmentIds = [];
        $roomIds = [];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            foreach ($this->identifierList($assignment['subject_offering_ids'] ?? null) as $offeringId) {
                $offeringIds[$offeringId] = true;
            }

            if (is_string($assignment['source_requirement_id'] ?? null)) {
                $requirementIds[$assignment['source_requirement_id']] = true;
            }

            if (is_string($assignment['professor_id'] ?? null)) {
                $professorIds[$assignment['professor_id']] = true;
            }

            if (is_string($assignment['room_id'] ?? null)) {
                $roomIds[$assignment['room_id']] = true;
            }

            foreach (is_array($assignment['professor_assignments'] ?? null) ? $assignment['professor_assignments'] : [] as $professorAssignment) {
                if (is_array($professorAssignment) && is_string($professorAssignment['faculty_assignment_id'] ?? null)) {
                    $facultyAssignmentIds[$professorAssignment['faculty_assignment_id']] = true;
                }
            }
        }

        $offeringRows = SubjectOffering::query()
            ->whereIn('id', array_map('intval', array_keys($offeringIds)))
            ->where('academic_term_id', $generation->academic_term_id)
            ->where('status', 'open')
            ->get()
            ->keyBy('id');

        $requirementRows = SchedulingMeetingRequirement::query()
            ->whereIn('id', array_map('intval', array_keys($requirementIds)))
            ->with('plan')
            ->get()
            ->keyBy('id');

        $professorRows = Professor::query()
            ->whereIn('id', array_map('intval', array_keys($professorIds)))
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        $facultyRows = FacultyAssignment::query()
            ->whereIn('id', array_map('intval', array_keys($facultyAssignmentIds)))
            ->get()
            ->keyBy('id');

        $roomRows = Room::query()
            ->whereIn('id', array_map('intval', array_keys($roomIds)))
            ->where('status', 'available')
            ->get()
            ->keyBy('id');

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                $errors[] = 'invalid_assignment_shape';

                continue;
            }

            $assignmentOfferingIds = $this->identifierList($assignment['subject_offering_ids'] ?? null);
            $assignmentOfferingSet = $this->identifierSet($assignment['subject_offering_ids'] ?? null);
            $assignmentOfferingInts = [];

            foreach ($assignmentOfferingIds as $offeringId) {
                if (! isset($requestOfferingIds[$offeringId]) || ! $offeringRows->has((int) $offeringId)) {
                    $errors[] = "unknown_offering:{$offeringId}";
                }

                $assignmentOfferingInts[(int) $offeringId] = true;
            }

            $requirementId = $assignment['source_requirement_id'] ?? null;
            if (! is_string($requirementId)
                || ! isset($requestRequirementIds[$requirementId])
                || ! $requirementRows->has((int) $requirementId)) {
                $errors[] = 'unknown_source_requirement';
            } else {
                $requirement = $requirementRows->get((int) $requirementId);

                if ($requirement?->plan === null
                    || ! isset($assignmentOfferingInts[(int) $requirement->plan->subject_offering_id])) {
                    $errors[] = 'source_requirement_offering_mismatch';
                }
            }

            $professorId = $assignment['professor_id'] ?? null;
            if (! is_string($professorId)
                || ! isset($requestProfessorIds[$professorId])
                || ! $professorRows->has((int) $professorId)) {
                $errors[] = 'unknown_professor';
            }

            $roomId = $assignment['room_id'] ?? null;
            if ($roomId !== null
                && (! is_string($roomId)
                    || ! isset($requestRoomIds[$roomId])
                    || ! $roomRows->has((int) $roomId))) {
                $errors[] = 'unknown_room';
            }

            foreach (is_array($assignment['professor_assignments'] ?? null) ? $assignment['professor_assignments'] : [] as $professorAssignment) {
                if (! is_array($professorAssignment)) {
                    $errors[] = 'invalid_professor_assignment_shape';

                    continue;
                }

                $facultyAssignmentId = $professorAssignment['faculty_assignment_id'] ?? null;
                $offeringId = $professorAssignment['subject_offering_id'] ?? null;

                if (! is_string($facultyAssignmentId)
                    || ! isset($requestFacultyAssignments[$facultyAssignmentId])
                    || ! $facultyRows->has((int) $facultyAssignmentId)) {
                    $errors[] = 'unknown_faculty_assignment';

                    continue;
                }

                $facultyAssignment = $facultyRows->get((int) $facultyAssignmentId);

                if ($facultyAssignment->status !== 'active'
                    || ! is_string($offeringId)
                    || ! isset($assignmentOfferingSet[$offeringId])
                    || (int) $facultyAssignment->subject_offering_id !== (int) $offeringId
                    || (string) $facultyAssignment->professor_id !== (string) $professorId) {
                    $errors[] = 'faculty_assignment_mismatch';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    private function persistCandidate(
        SchedulingGeneration $generation,
        array $payload,
        array $result,
        string $validationStatus,
        string $sourceResponseHash,
    ): SchedulingCandidate {
        $version = (int) SchedulingCandidate::query()
            ->where('academic_term_id', $generation->academic_term_id)
            ->max('version') + 1;

        $summary = is_array($result['validation_summary'] ?? null) ? $result['validation_summary'] : [];

        $candidate = SchedulingCandidate::query()->create([
            'uuid' => (string) Str::uuid(),
            'scheduling_generation_id' => $generation->id,
            'academic_term_id' => $generation->academic_term_id,
            'version' => $version,
            'status' => 'generated',
            'validation_status' => $validationStatus,
            'validation_summary' => $summary,
            'validation_errors' => is_array($summary['validation_errors'] ?? null)
                ? $summary['validation_errors']
                : [],
            'snapshot_sha256' => (string) ($result['snapshot_sha256'] ?? Arr::get($payload, 'metadata.snapshot_sha256', '')),
            'source_response_sha256' => $sourceResponseHash,
            'created_by_user_id' => $generation->requested_by_user_id,
        ]);

        CandidateStatusEvent::query()->create([
            'scheduling_candidate_id' => $candidate->id,
            'to_status' => 'generated',
            'metadata' => [
                'generation_uuid' => $generation->uuid,
                'result_status' => $result['result_status'] ?? null,
            ],
            'created_at' => now(),
        ]);

        $baselines = collect(is_array($payload['published_timetable_baseline'] ?? null)
            ? $payload['published_timetable_baseline']
            : [])
            ->filter(fn ($baseline): bool => is_array($baseline))
            ->keyBy(function (array $baseline): string {
                $roomAllocations = is_array($baseline['room_allocations'] ?? null) ? $baseline['room_allocations'] : [];
                $firstRoom = is_array($roomAllocations[0] ?? null) ? $roomAllocations[0] : [];

                return $this->baselineKey(
                    $baseline['meeting_instance_id'] ?? null,
                    is_array($baseline['subject_offering_ids'] ?? null)
                        ? $baseline['subject_offering_ids']
                        : [$baseline['subject_offering_id'] ?? null],
                    $baseline['day_of_week'] ?? null,
                    $baseline['start_time'] ?? null,
                    $baseline['end_time'] ?? null,
                    $firstRoom['room_id'] ?? null,
                );
            });

        foreach (is_array($result['assignments'] ?? null) ? $result['assignments'] : [] as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $meetingInstanceId = (string) ($assignment['meeting_instance_id'] ?? '');
            $baselineKey = $this->baselineKey(
                null,
                is_array($assignment['subject_offering_ids'] ?? null) ? $assignment['subject_offering_ids'] : [],
                $assignment['day_of_week'] ?? null,
                $assignment['start_time'] ?? null,
                $assignment['end_time'] ?? null,
                $assignment['room_id'] ?? null,
            );
            $baseline = $baselines->get($baselineKey);
            $previousRoomId = null;

            if (is_array($baseline) && is_array($baseline['room_allocations'] ?? null)) {
                $firstRoom = $baseline['room_allocations'][0] ?? null;
                $previousRoomId = is_array($firstRoom) ? ($firstRoom['room_id'] ?? null) : null;
            }

            $placementChangeStatus = 'not_comparable';
            if (is_array($baseline)) {
                $samePlacement = (string) ($baseline['day_of_week'] ?? '') === (string) ($assignment['day_of_week'] ?? '')
                    && (string) ($baseline['start_time'] ?? '') === (string) ($assignment['start_time'] ?? '')
                    && (string) ($baseline['end_time'] ?? '') === (string) ($assignment['end_time'] ?? '')
                    && (string) $previousRoomId === (string) ($assignment['room_id'] ?? '');

                $placementChangeStatus = $samePlacement ? 'unchanged' : 'moved';
            }

            $placement = CandidateMeetingPlacement::query()->create([
                'uuid' => (string) Str::uuid(),
                'scheduling_candidate_id' => $candidate->id,
                'offering_meeting_requirement_id' => (int) $assignment['source_requirement_id'],
                'previous_published_schedule_id' => is_array($baseline)
                    ? $this->nullableInt($baseline['schedule_id'] ?? null)
                    : null,
                'meeting_instance_id' => $meetingInstanceId,
                'occurrence_number' => (int) $assignment['occurrence_number'],
                'day_of_week' => (int) $assignment['day_of_week'],
                'start_time' => (string) $assignment['start_time'],
                'end_time' => (string) $assignment['end_time'],
                'duration_minutes' => (int) $assignment['duration_minutes'],
                'meeting_type' => (string) $assignment['meeting_type'],
                'modality' => (string) $assignment['modality'],
                'is_primary' => true,
                'status' => 'validated',
                'previous_meeting_instance_id' => is_array($baseline)
                    ? ($baseline['meeting_instance_id'] ?? null)
                    : null,
                'placement_change_status' => $placementChangeStatus,
                'previous_day_of_week' => is_array($baseline)
                    ? $this->nullableInt($baseline['day_of_week'] ?? null)
                    : null,
                'previous_start_time' => is_array($baseline) ? ($baseline['start_time'] ?? null) : null,
                'previous_end_time' => is_array($baseline) ? ($baseline['end_time'] ?? null) : null,
            ]);

            $offeringRowsByStringId = [];

            foreach (array_values($this->identifierList($assignment['subject_offering_ids'] ?? null)) as $index => $offeringId) {
                $offeringRowsByStringId[$offeringId] = CandidateMeetingOffering::query()->create([
                    'candidate_meeting_placement_id' => $placement->id,
                    'subject_offering_id' => (int) $offeringId,
                    'is_primary' => $index === 0,
                ]);
            }

            $professorAssignments = array_values(array_filter(
                is_array($assignment['professor_assignments'] ?? null) ? $assignment['professor_assignments'] : [],
                fn ($professorAssignment): bool => is_array($professorAssignment),
            ));
            $hasLead = collect($professorAssignments)->contains(
                fn (array $professorAssignment): bool => ($professorAssignment['assignment_role'] ?? null) === 'lead',
            );

            foreach ($professorAssignments as $index => $professorAssignment) {
                $offeringId = (string) ($professorAssignment['subject_offering_id'] ?? '');
                $offeringRow = $offeringRowsByStringId[$offeringId] ?? null;

                if ($offeringRow === null) {
                    throw new RuntimeException('A professor assignment references an unknown meeting offering.');
                }

                CandidateMeetingProfessor::query()->create([
                    'candidate_meeting_placement_id' => $placement->id,
                    'candidate_meeting_offering_id' => $offeringRow->id,
                    'subject_offering_id' => (int) $offeringId,
                    'faculty_assignment_id' => (int) $professorAssignment['faculty_assignment_id'],
                    'is_primary' => ($professorAssignment['assignment_role'] ?? null) === 'lead'
                        || (! $hasLead && $index === 0),
                ]);
            }

            $roomId = $assignment['room_id'] ?? null;
            if ($roomId !== null) {
                CandidateRoomAllocation::query()->create([
                    'candidate_meeting_placement_id' => $placement->id,
                    'room_id' => (int) $roomId,
                    'status' => 'proposed',
                ]);
            }
        }

        return $candidate->load('placements');
    }

    private function baselineKey(
        mixed $meetingInstanceId,
        mixed $offeringIds,
        mixed $dayOfWeek,
        mixed $startTime,
        mixed $endTime,
        mixed $roomId,
    ): string {
        if (is_string($meetingInstanceId) && $meetingInstanceId !== '') {
            return 'instance:'.$meetingInstanceId;
        }

        $identifiers = is_array($offeringIds)
            ? array_values(array_unique(array_filter(array_map('strval', $offeringIds), static fn (string $id): bool => $id !== '')))
            : [];
        sort($identifiers, SORT_STRING);

        return implode('|', [
            'placement',
            implode(',', $identifiers),
            $dayOfWeek === null ? '' : (string) $dayOfWeek,
            $startTime === null ? '' : (string) $startTime,
            $endTime === null ? '' : (string) $endTime,
            $roomId === null ? '' : (string) $roomId,
        ]);
    }

    private function inputMetrics(array $payload): array
    {
        return [
            'offerings' => count(is_array($payload['offerings'] ?? null) ? $payload['offerings'] : []),
            'professors' => count(is_array($payload['professors'] ?? null) ? $payload['professors'] : []),
            'professor_assignments' => count(is_array($payload['professor_assignments'] ?? null)
                ? $payload['professor_assignments']
                : []),
            'rooms' => count(is_array($payload['rooms'] ?? null) ? $payload['rooms'] : []),
            'published_baselines' => count(is_array($payload['published_timetable_baseline'] ?? null)
                ? $payload['published_timetable_baseline']
                : []),
        ];
    }

    private function userId(?Authenticatable $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function identifierList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));
    }

    private function identifierSet(mixed $value): array
    {
        $set = [];

        foreach ($this->identifierList($value) as $identifier) {
            $set[$identifier] = true;
        }

        return $set;
    }

    private function column(mixed $rows, string $key): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(fn ($row): ?string => is_array($row) && is_string($row[$key] ?? null) ? $row[$key] : null)
            ->filter()
            ->values()
            ->all();
    }
}

<?php

namespace App\Services\Scheduling;

use App\Exceptions\InvalidCandidateRevisionTransitionException;
use App\Exceptions\StaleCandidateRevisionException;
use App\Models\CandidateRevisionStatusEvent;
use App\Models\SchedulingCandidate;
use App\Models\SchedulingCandidateRevision;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SchedulingCandidateRevisionService
{
    private const ACTIVE_STATUSES = ['draft', 'under_review', 'validated', 'needs_revision'];

    private const EDITABLE_STATUSES = ['draft', 'needs_revision'];

    private const VALIDATABLE_STATUSES = ['draft', 'under_review', 'needs_revision'];

    private const TRANSITIONS = [
        'draft' => ['under_review', 'superseded', 'discarded'],
        'under_review' => ['validated', 'needs_revision', 'superseded', 'discarded'],
        'validated' => ['needs_revision', 'superseded', 'discarded'],
        'needs_revision' => ['draft', 'superseded', 'discarded'],
    ];

    private const EVENT_TYPE_STATUS_TRANSITION = 'status_transition';

    private const EVENT_TYPE_CONTENT_UPDATE = 'content_update';

    private const EVENT_TYPE_VALIDATION = 'validation';

    private const EVENT_TYPES = [
        self::EVENT_TYPE_STATUS_TRANSITION,
        self::EVENT_TYPE_CONTENT_UPDATE,
        self::EVENT_TYPE_VALIDATION,
    ];

    private const ASSIGNMENT_ROLES = ['lead', 'co_instructor', 'lab_instructor', 'substitute'];

    public function createFromCandidate(
        SchedulingCandidate $candidate,
        ?Authenticatable $user,
        string $changeSummary,
        ?int $expectedCandidateVersion = null,
    ): SchedulingCandidateRevision {
        return DB::transaction(function () use ($candidate, $user, $changeSummary, $expectedCandidateVersion): SchedulingCandidateRevision {
            $lockedCandidate = $this->lockCandidate($candidate);
            $this->assertCandidateVersion($lockedCandidate, $expectedCandidateVersion);
            $this->assertNoActiveRevision($lockedCandidate);
            $this->assertNonEmptyReason($changeSummary, 'A change summary is required.');

            $content = $this->contentFromCandidate($lockedCandidate);
            $revisionNumber = $this->nextRevisionNumber($lockedCandidate->id);

            return $this->createRevision(
                $lockedCandidate,
                null,
                $revisionNumber,
                $content,
                $user,
                $changeSummary,
            );
        });
    }

    public function createFromRevision(
        SchedulingCandidateRevision $revision,
        ?Authenticatable $user,
        string $changeSummary,
        int $expectedVersion,
    ): SchedulingCandidateRevision {
        return DB::transaction(function () use ($revision, $user, $changeSummary, $expectedVersion): SchedulingCandidateRevision {
            $lockedRevision = $this->lockRevision($revision);
            $this->assertNonEmptyReason($changeSummary, 'A change summary is required.');
            $this->assertVersion($lockedRevision, $expectedVersion);

            $lockedCandidate = $this->lockCandidateForRevision($lockedRevision);
            $this->assertCurrentActiveRevision($lockedCandidate, $lockedRevision);
            $this->assertSnapshotIsCurrent($lockedRevision, $lockedCandidate);

            if (! array_key_exists($lockedRevision->status, self::TRANSITIONS)
                || ! in_array('superseded', self::TRANSITIONS[$lockedRevision->status], true)) {
                throw new InvalidCandidateRevisionTransitionException('This candidate revision cannot be superseded.');
            }

            $nextNumber = $this->nextRevisionNumber($lockedCandidate->id);
            $content = $this->decodeContent($lockedRevision->content_canonical);
            $sourceHash = (string) $lockedRevision->content_sha256;

            $this->changeStatusLocked(
                $lockedRevision,
                'superseded',
                $lockedRevision->version,
                $user,
                $changeSummary,
                [
                    'superseded_by_revision_number' => $nextNumber,
                ],
            );

            return $this->createRevision(
                $lockedCandidate,
                $lockedRevision->id,
                $nextNumber,
                $content,
                $user,
                $changeSummary,
                $sourceHash,
            );
        });
    }

    public function updateContent(
        SchedulingCandidateRevision $revision,
        int $expectedVersion,
        array $changes,
        ?Authenticatable $user,
        string $changeSummary,
    ): SchedulingCandidateRevision {
        return DB::transaction(function () use ($revision, $expectedVersion, $changes, $user, $changeSummary): SchedulingCandidateRevision {
            $locked = $this->lockRevision($revision);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertNonEmptyReason($changeSummary, 'A change summary is required.');

            if (! in_array($locked->status, self::EDITABLE_STATUSES, true)) {
                throw new InvalidCandidateRevisionTransitionException('Only draft or needs-revision candidates can be edited.');
            }

            if ($changes === []) {
                throw new InvalidArgumentException('At least one revision content change is required.');
            }

            $candidate = $this->lockCandidateForRevision($locked);
            $this->assertSnapshotIsCurrent($locked, $candidate);
            $this->assertCurrentActiveRevision($candidate, $locked);

            $content = $this->decodeContent($locked->content_canonical);

            foreach (['validation_summary', 'assignments'] as $key) {
                if (array_key_exists($key, $changes)) {
                    $content[$key] = $changes[$key];
                }
            }

            $content = $this->normaliseContent($content, (string) $locked->base_snapshot_sha256);
            $contentHash = $this->contentHash($content);
            $observedVersion = $locked->version + 1;

            $locked->forceFill([
                'content_canonical' => SnapshotHasher::canonicalJson($content),
                'content_sha256' => $contentHash,
                'validation_status' => 'not_validated',
                'version' => $observedVersion,
                'updated_by_user_id' => $this->userId($user),
                'change_summary' => $changeSummary,
            ])->save();

            $this->recordEvent(
                $locked,
                $locked->status,
                $locked->status,
                $user,
                $changeSummary,
                $expectedVersion,
                $observedVersion,
                [
                    'event_type' => self::EVENT_TYPE_CONTENT_UPDATE,
                    'changed_keys' => array_values(array_intersect(['validation_summary', 'assignments'], array_keys($changes))),
                    'content_sha256' => $contentHash,
                ],
            );

            return $locked;
        });
    }

    public function validate(
        SchedulingCandidateRevision $revision,
        int $expectedVersion,
        ?Authenticatable $user,
        string $reason,
    ): array {
        return DB::transaction(function () use ($revision, $expectedVersion, $user, $reason): array {
            $locked = $this->lockRevision($revision);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertNonEmptyReason($reason, 'A validation reason is required.');

            if (! in_array($locked->status, self::VALIDATABLE_STATUSES, true)) {
                throw new InvalidCandidateRevisionTransitionException('Only active candidate revisions can be revalidated.');
            }

            $candidate = $this->lockCandidateForRevision($locked);
            $this->assertSnapshotIsCurrent($locked, $candidate);
            $this->assertCurrentActiveRevision($candidate, $locked);

            $generation = $candidate->generation;
            $snapshot = $generation?->snapshot;

            if ($generation === null || $snapshot === null || ! is_array($generation->response_payload)) {
                throw new InvalidArgumentException('The original scheduling response and snapshot are unavailable for revalidation.');
            }

            $request = json_decode($snapshot->content_canonical, true);
            if (! is_array($request)) {
                throw new InvalidArgumentException('The original scheduling snapshot cannot be decoded.');
            }

            $request['contract_version'] = $generation->contract_version;
            $request['metadata'] = [
                'generation_id' => $generation->uuid,
                'snapshot_sha256' => $snapshot->content_sha256,
                'correlation_id' => $generation->correlation_id,
            ];

            $content = $this->decodeContent($locked->content_canonical);
            $assignments = is_array($content['assignments'] ?? null) ? $content['assignments'] : [];
            $summary = is_array($content['validation_summary'] ?? null) ? $content['validation_summary'] : [];
            $summary['independent_validation_status'] = 'passed';
            $summary['validation_errors'] = [];
            $summary['scheduled_meeting_count'] = count($assignments);
            $summary['represented_offering_count'] = $this->representedOfferingCount($assignments);

            $response = $generation->response_payload;
            $response['assignments'] = $assignments;
            $response['validation_summary'] = $summary;
            $response['workload_metrics'] = $this->workloadMetrics($request, $assignments);
            $response['full_time_distribution'] = $this->fullTimeDistribution($response['workload_metrics']);

            $errors = SchedulerResponseValidator::validate($response, $request);
            $validationStatus = $errors === [] ? 'valid' : 'invalid';
            $summary['independent_validation_status'] = $errors === [] ? 'passed' : 'failed';
            $summary['validation_errors'] = $errors;
            $content['validation_summary'] = $summary;
            $contentHash = $this->contentHash($content);
            $observedVersion = $locked->version + 1;
            $fromStatus = $locked->status;
            $nextStatus = $errors !== [] && $fromStatus === 'under_review'
                ? 'needs_revision'
                : $fromStatus;

            $locked->forceFill([
                'content_canonical' => SnapshotHasher::canonicalJson($content),
                'content_sha256' => $contentHash,
                'validation_status' => $validationStatus,
                'version' => $observedVersion,
                'updated_by_user_id' => $this->userId($user),
                'change_summary' => $reason,
            ])->save();

            $this->recordEvent(
                $locked,
                $fromStatus,
                $nextStatus,
                $user,
                $reason,
                $expectedVersion,
                $observedVersion,
                [
                    'event_type' => self::EVENT_TYPE_VALIDATION,
                    'validation_status' => $validationStatus,
                    'error_count' => count($errors),
                    'errors' => $errors,
                    'content_sha256' => $contentHash,
                ],
            );

            if ($nextStatus !== $fromStatus) {
                $this->changeStatusLocked(
                    $locked,
                    $nextStatus,
                    $observedVersion,
                    $user,
                    $reason,
                    ['validation_failed' => true],
                );
            }

            return [
                'revision' => $locked,
                'errors' => $errors,
            ];
        });
    }

    public function transition(
        SchedulingCandidateRevision $revision,
        int $expectedVersion,
        string $toStatus,
        string $reason,
        ?Authenticatable $user,
    ): SchedulingCandidateRevision {
        return DB::transaction(function () use ($revision, $expectedVersion, $toStatus, $reason, $user): SchedulingCandidateRevision {
            $locked = $this->lockRevision($revision);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertNonEmptyReason($reason, 'A transition reason is required.');

            $candidate = $this->lockCandidateForRevision($locked);
            $this->assertCurrentActiveRevision($candidate, $locked);
            $this->assertSnapshotIsCurrent($locked, $candidate);

            $allowed = self::TRANSITIONS[$locked->status] ?? [];
            if (! in_array($toStatus, $allowed, true)) {
                throw new InvalidCandidateRevisionTransitionException(
                    "Cannot transition from {$locked->status} to {$toStatus}.",
                );
            }

            if (in_array($toStatus, ['under_review', 'validated'], true)
                && $locked->validation_status !== 'valid') {
                throw new InvalidCandidateRevisionTransitionException('Only a valid candidate revision can enter review or validation.');
            }

            return $this->changeStatusLocked($locked, $toStatus, $expectedVersion, $user, $reason);
        });
    }

    public function summary(SchedulingCandidateRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'uuid' => $revision->uuid,
            'revision_number' => $revision->revision_number,
            'parent_revision_id' => $revision->parent_revision_id,
            'status' => $revision->status,
            'validation_status' => $revision->validation_status,
            'version' => $revision->version,
            'content_sha256' => $revision->content_sha256,
            'base_snapshot_sha256' => $revision->base_snapshot_sha256,
            'base_revision_sha256' => $revision->base_revision_sha256,
            'change_summary' => $revision->change_summary,
            'created_by_user_id' => $revision->created_by_user_id,
            'updated_by_user_id' => $revision->updated_by_user_id,
            'validated_by_user_id' => $revision->validated_by_user_id,
            'submitted_at' => $revision->submitted_at?->toIso8601String(),
            'validated_at' => $revision->validated_at?->toIso8601String(),
            'created_at' => $revision->created_at?->toIso8601String(),
            'updated_at' => $revision->updated_at?->toIso8601String(),
        ];
    }

    public function eventSummary(CandidateRevisionStatusEvent $event): array
    {
        $eventType = $this->eventType($event);
        $metadata = is_array($event->metadata) ? $event->metadata : [];

        return [
            'id' => $event->id,
            'event_type' => $eventType,
            'is_status_transition' => $eventType === self::EVENT_TYPE_STATUS_TRANSITION,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'actor_user_id' => $event->actor_user_id,
            'reason' => $event->reason,
            'expected_version' => $event->expected_version,
            'observed_version' => $event->observed_version,
            'metadata' => $metadata,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private function createRevision(
        SchedulingCandidate $candidate,
        ?int $parentRevisionId,
        int $revisionNumber,
        array $content,
        ?Authenticatable $user,
        string $changeSummary,
        ?string $baseRevisionHash = null,
    ): SchedulingCandidateRevision {
        $revision = SchedulingCandidateRevision::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'scheduling_candidate_id' => $candidate->id,
            'parent_revision_id' => $parentRevisionId,
            'revision_number' => $revisionNumber,
            'status' => 'draft',
            'validation_status' => 'not_validated',
            'content_canonical' => SnapshotHasher::canonicalJson($content),
            'content_sha256' => $baseRevisionHash ?? $this->contentHash($content),
            'base_snapshot_sha256' => $candidate->snapshot_sha256,
            'base_revision_sha256' => $baseRevisionHash,
            'version' => 1,
            'change_summary' => $changeSummary,
            'created_by_user_id' => $this->userId($user),
        ]);

        $this->recordEvent(
            $revision,
            null,
            'draft',
            $user,
            $changeSummary,
            null,
            1,
            [
                'event_type' => self::EVENT_TYPE_STATUS_TRANSITION,
                'revision_number' => $revisionNumber,
                'parent_revision_id' => $parentRevisionId,
                'initial_state' => $parentRevisionId === null,
            ],
        );

        return $revision;
    }

    private function changeStatusLocked(
        SchedulingCandidateRevision $revision,
        string $toStatus,
        int $expectedVersion,
        ?Authenticatable $user,
        string $reason,
        array $metadata = [],
    ): SchedulingCandidateRevision {
        $fromStatus = $revision->status;
        $this->assertStatusValidationCompatible(
            $toStatus,
            is_string($revision->validation_status) ? $revision->validation_status : 'not_validated',
        );

        $attributes = [
            'status' => $toStatus,
            'version' => $revision->version + 1,
            'updated_by_user_id' => $this->userId($user),
        ];

        if ($toStatus === 'under_review') {
            $attributes['submitted_at'] = now();
        }

        if ($toStatus === 'validated') {
            $attributes['validated_at'] = now();
            $attributes['validated_by_user_id'] = $this->userId($user);
        }

        $revision->forceFill($attributes)->save();

        $this->recordEvent(
            $revision,
            $fromStatus,
            $toStatus,
            $user,
            $reason,
            $expectedVersion,
            $attributes['version'],
            [
                'event_type' => self::EVENT_TYPE_STATUS_TRANSITION,
                ...$metadata,
            ],
        );

        return $revision;
    }

    private function assertVersion(SchedulingCandidateRevision $revision, int $expectedVersion): void
    {
        if ($revision->version !== $expectedVersion) {
            throw new StaleCandidateRevisionException(
                "Candidate revision version {$expectedVersion} is stale; current version is {$revision->version}.",
            );
        }
    }

    private function lockCandidate(SchedulingCandidate $candidate): SchedulingCandidate
    {
        return SchedulingCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
    }

    private function lockRevision(SchedulingCandidateRevision $revision): SchedulingCandidateRevision
    {
        return SchedulingCandidateRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
    }

    private function lockCandidateForRevision(SchedulingCandidateRevision $revision): SchedulingCandidate
    {
        return SchedulingCandidate::query()
            ->whereKey($revision->scheduling_candidate_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertCandidateVersion(SchedulingCandidate $candidate, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $candidate->version !== $expectedVersion) {
            throw new StaleCandidateRevisionException(
                "Candidate version {$expectedVersion} is stale; current version is {$candidate->version}.",
            );
        }
    }

    private function assertNoActiveRevision(SchedulingCandidate $candidate): void
    {
        $exists = SchedulingCandidateRevision::query()
            ->where('scheduling_candidate_id', $candidate->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw new StaleCandidateRevisionException(
                'This candidate already has an active revision.',
            );
        }
    }

    private function assertCurrentActiveRevision(
        SchedulingCandidate $candidate,
        SchedulingCandidateRevision $revision,
    ): void {
        $activeRevisions = SchedulingCandidateRevision::query()
            ->where('scheduling_candidate_id', $candidate->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->lockForUpdate()
            ->get(['id']);

        if ($activeRevisions->count() !== 1 || ! $activeRevisions->contains('id', $revision->id)) {
            throw new StaleCandidateRevisionException(
                'The supplied revision is not the candidate’s current active revision.',
            );
        }
    }

    private function assertStatusValidationCompatible(string $status, string $validationStatus): void
    {
        if (in_array($status, ['under_review', 'validated'], true) && $validationStatus !== 'valid') {
            throw new InvalidCandidateRevisionTransitionException(
                'A revision cannot enter review or validation without a valid validation result.',
            );
        }
    }

    private function assertNonEmptyReason(string $reason, string $message): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException($message);
        }
    }

    private function eventType(CandidateRevisionStatusEvent $event): string
    {
        $type = Arr::get(is_array($event->metadata) ? $event->metadata : [], 'event_type');

        return is_string($type) && in_array($type, self::EVENT_TYPES, true)
            ? $type
            : self::EVENT_TYPE_STATUS_TRANSITION;
    }

    private function assertSnapshotIsCurrent(
        SchedulingCandidateRevision $revision,
        SchedulingCandidate $candidate,
    ): void {
        if ($revision->base_snapshot_sha256 !== $candidate->snapshot_sha256) {
            throw new StaleCandidateRevisionException('The source scheduling snapshot changed after this revision was created.');
        }
    }

    private function nextRevisionNumber(int $candidateId): int
    {
        return (int) SchedulingCandidateRevision::query()
            ->where('scheduling_candidate_id', $candidateId)
            ->max('revision_number') + 1;
    }

    private function recordEvent(
        SchedulingCandidateRevision $revision,
        ?string $fromStatus,
        string $toStatus,
        ?Authenticatable $user,
        string $reason,
        ?int $expectedVersion,
        int $observedVersion,
        array $metadata,
    ): void {
        CandidateRevisionStatusEvent::query()->forceCreate([
            'scheduling_candidate_revision_id' => $revision->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $this->userId($user),
            'reason' => $reason,
            'expected_version' => $expectedVersion,
            'observed_version' => $observedVersion,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function publicationContent(SchedulingCandidate $candidate): array
    {
        $active = SchedulingCandidateRevision::query()
            ->where('scheduling_candidate_id', $candidate->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('revision_number')
            ->first();

        if ($active !== null) {
            if ($active->status !== 'validated' || $active->validation_status !== 'valid') {
                throw new InvalidCandidateRevisionTransitionException(
                    'The active candidate revision must be validated before the candidate can advance or publish.',
                );
            }

            return $this->decodeContent($active->content_canonical);
        }

        if ($candidate->validation_status !== 'valid') {
            throw new InvalidArgumentException('Only a valid scheduling candidate can advance or publish.');
        }

        return $this->contentFromCandidate($candidate);
    }

    private function contentFromCandidate(SchedulingCandidate $candidate): array
    {
        $generation = $candidate->generation;
        $response = is_array($generation?->response_payload) ? $generation->response_payload : [];
        $assignments = is_array($response['assignments'] ?? null) ? $response['assignments'] : [];
        $summary = is_array($candidate->validation_summary) ? $candidate->validation_summary : [];

        return $this->normaliseContent([
            'snapshot_sha256' => $candidate->snapshot_sha256,
            'validation_summary' => $summary,
            'assignments' => $assignments,
        ], (string) $candidate->snapshot_sha256);
    }

    private function decodeContent(string $canonical): array
    {
        $decoded = json_decode($canonical, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normaliseContent(array $content, string $baseSnapshotHash): array
    {
        $assignments = $content['assignments'] ?? [];
        if (! is_array($assignments) || ! array_is_list($assignments)) {
            throw new InvalidArgumentException('assignments must be a list.');
        }

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)
                || ! $this->isIdentifier($assignment['meeting_instance_id'] ?? null)
                || ! $this->isPositiveInt($assignment['occurrence_number'] ?? null)
                || ! $this->isIdentifierList($assignment['subject_offering_ids'] ?? null)
                || ! $this->isIdentifierList($assignment['section_ids'] ?? null)
                || ! $this->isIdentifier($assignment['professor_id'] ?? null)
                || ! $this->isIdentifier($assignment['source_requirement_id'] ?? null)
                || ($assignment['is_primary'] ?? null) !== true
                || ! $this->isIdentifier($assignment['room_id'] ?? null, true)
                || ! is_string($assignment['meeting_type'] ?? null)
                || ! is_string($assignment['modality'] ?? null)
                || ! $this->isWeekday($assignment['day_of_week'] ?? null)
                || ! $this->isClockTime($assignment['start_time'] ?? null)
                || ! $this->isClockTime($assignment['end_time'] ?? null)
                || ! $this->isPositiveInt($assignment['duration_minutes'] ?? null)) {
                throw new InvalidArgumentException('Each assignment must match the Contract v1.0 assignment shape.');
            }

            $professorAssignments = $assignment['professor_assignments'] ?? null;
            if (! is_array($professorAssignments) || ! array_is_list($professorAssignments) || $professorAssignments === []) {
                throw new InvalidArgumentException('Each assignment requires at least one professor assignment.');
            }

            foreach ($professorAssignments as $professorAssignment) {
                if (! is_array($professorAssignment)
                    || ! $this->isIdentifier($professorAssignment['subject_offering_id'] ?? null)
                    || ! $this->isIdentifier($professorAssignment['faculty_assignment_id'] ?? null)
                    || ! in_array($professorAssignment['assignment_role'] ?? null, self::ASSIGNMENT_ROLES, true)) {
                    throw new InvalidArgumentException('Each professor assignment must contain valid Contract v1.0 identifiers and role.');
                }
            }
        }

        return [
            'snapshot_sha256' => $baseSnapshotHash,
            'validation_summary' => is_array($content['validation_summary'] ?? null) ? $content['validation_summary'] : [],
            'assignments' => $assignments,
        ];
    }

    private function isIdentifier(mixed $value, bool $nullable = false): bool
    {
        if ($nullable && $value === null) {
            return true;
        }

        return is_string($value) && $value !== '';
    }

    private function isIdentifierList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->isIdentifier($item)) {
                return false;
            }
        }

        return true;
    }

    private function isPositiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function isWeekday(mixed $value): bool
    {
        return is_int($value) && $value >= 1 && $value <= 5;
    }

    private function isClockTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private function workloadMetrics(array $request, array $assignments): array
    {
        $professors = [];

        foreach (is_array($request['professors'] ?? null) ? $request['professors'] : [] as $professor) {
            if (is_array($professor) && is_string($professor['id'] ?? null) && $professor['id'] !== '') {
                $professors[$professor['id']] = $professor;
            }
        }

        $minutes = array_fill_keys(array_keys($professors), 0);

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $professorId = $assignment['professor_id'] ?? null;
            if (is_string($professorId) && array_key_exists($professorId, $minutes)) {
                $minutes[$professorId] += (int) ($assignment['duration_minutes'] ?? 0);
            }
        }

        $metrics = [];

        foreach ($professors as $professorId => $professor) {
            $metric = [
                'professor_id' => $professorId,
                'assigned_teaching_minutes' => $minutes[$professorId],
            ];

            if (array_key_exists('employment_type', $professor)) {
                $metric['employment_type'] = $professor['employment_type'];
            }

            if (array_key_exists('part_time_weekly_target_minutes', $professor)) {
                $metric['weekly_target_minutes'] = $professor['part_time_weekly_target_minutes'];
            }

            $metrics[] = $metric;
        }

        return $metrics;
    }

    private function fullTimeDistribution(array $metrics): ?array
    {
        $minutes = [];

        foreach ($metrics as $metric) {
            if (is_array($metric) && ($metric['employment_type'] ?? null) === 'full_time') {
                $minutes[] = (int) ($metric['assigned_teaching_minutes'] ?? 0);
            }
        }

        if ($minutes === []) {
            return null;
        }

        sort($minutes);
        $minimum = $minutes[0];
        $maximum = $minutes[count($minutes) - 1];

        return [
            'minimum_assigned_minutes' => $minimum,
            'maximum_assigned_minutes' => $maximum,
            'spread_minutes' => $maximum - $minimum,
        ];
    }

    private function representedOfferingCount(array $assignments): int
    {
        $offeringIds = [];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            foreach (is_array($assignment['subject_offering_ids'] ?? null) ? $assignment['subject_offering_ids'] : [] as $offeringId) {
                if (is_string($offeringId)) {
                    $offeringIds[$offeringId] = true;
                }
            }
        }

        return count($offeringIds);
    }

    private function contentHash(array $content): string
    {
        return hash('sha256', SnapshotHasher::canonicalJson($content));
    }

    private function userId(?Authenticatable $user): ?int
    {
        if ($user === null) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}

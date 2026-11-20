<?php

namespace App\Services\Scheduling;

use App\Exceptions\InvalidSchedulingCandidateTransitionException;
use App\Exceptions\StaleSchedulingCandidateException;
use App\Models\AcademicTerm;
use App\Models\CandidateStatusEvent;
use App\Models\ClassSchedule;
use App\Models\ClassScheduleEvent;
use App\Models\FacultyAssignment;
use App\Models\Room;
use App\Models\RoomAllocation;
use App\Models\ScheduleAssignment;
use App\Models\SchedulingCandidate;
use App\Models\SchedulingGeneration;
use App\Models\SubjectOffering;
use App\Models\TimetablePublication;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SchedulingCandidateLifecycleService
{
    private const VALIDATION_BEFORE_STATUS = ['under_review', 'approved', 'published'];

    public function __construct(
        private readonly SchedulingCandidateRevisionService $revisions,
        private readonly SchedulingRequestBuilder $requests,
    ) {}

    public function review(
        SchedulingCandidate $candidate,
        int $expectedVersion,
        ?Authenticatable $user,
        string $reason,
    ): SchedulingCandidate {
        return $this->transition(
            $candidate,
            ['generated'],
            'under_review',
            $expectedVersion,
            $user,
            $reason !== '' ? $reason : 'Candidate submitted for review.',
        );
    }

    public function approve(
        SchedulingCandidate $candidate,
        int $expectedVersion,
        ?Authenticatable $user,
        string $reason,
    ): SchedulingCandidate {
        return $this->transition(
            $candidate,
            ['under_review'],
            'approved',
            $expectedVersion,
            $user,
            $reason !== '' ? $reason : 'Candidate approved for publication.',
        );
    }

    public function reject(
        SchedulingCandidate $candidate,
        int $expectedVersion,
        ?Authenticatable $user,
        string $reason,
    ): SchedulingCandidate {
        return $this->transition(
            $candidate,
            ['generated', 'under_review'],
            'rejected',
            $expectedVersion,
            $user,
            $reason,
        );
    }

    public function publish(
        SchedulingCandidate $candidate,
        int $expectedVersion,
        ?Authenticatable $user,
        string $reason,
    ): array {
        return DB::transaction(function () use ($candidate, $expectedVersion, $user, $reason): array {
            $term = AcademicTerm::query()->whereKey($candidate->academic_term_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockCandidate($candidate);
            $this->assertVersion($locked, $expectedVersion);

            if ($locked->status === 'published') {
                $existing = TimetablePublication::query()
                    ->where('scheduling_candidate_id', $locked->id)
                    ->first();

                if ($existing === null) {
                    throw new InvalidArgumentException('This candidate is published but no publication record exists.');
                }

                return [
                    'publication' => $this->publicationSummary($existing),
                    'candidate' => $locked,
                    'created_schedule_count' => 0,
                    'superseded_schedule_count' => 0,
                    'idempotent' => true,
                ];
            }

            if ($locked->status !== 'approved') {
                throw new InvalidSchedulingCandidateTransitionException(
                    "Cannot publish a candidate in {$locked->status} status.",
                );
            }

            $generation = SchedulingGeneration::query()->whereKey($locked->scheduling_generation_id)->lockForUpdate()->firstOrFail();
            $this->assertSourceIsCurrent($locked, $generation, $term);
            $content = $this->revisions->publicationContent($locked);
            $assignments = is_array($content['assignments'] ?? null) ? $content['assignments'] : [];
            if ($assignments === []) {
                throw new InvalidArgumentException('The candidate has no placements to publish.');
            }

            $activePublication = $this->activePublicationForTerm($term->id);
            $supersededCount = $this->supersedeActiveTimetable($term, $activePublication, $user, $reason);

            if ($activePublication !== null) {
                $activePublication->forceFill([
                    'status' => 'superseded',
                    'superseded_at' => now(),
                ])->save();
            }

            $publication = TimetablePublication::query()->forceCreate([
                'uuid' => (string) Str::uuid(),
                'academic_term_id' => $term->id,
                'scheduling_candidate_id' => $locked->id,
                'publication_type' => 'candidate',
                'previous_publication_id' => $activePublication?->id,
                'published_by_user_id' => $this->userId($user),
                'status' => 'active',
                'published_at' => now(),
                'notes' => $reason !== '' ? $reason : null,
            ]);

            $createdCount = 0;
            foreach ($assignments as $assignment) {
                $createdCount += $this->publishAssignment($locked, $publication, $term, $assignment, $user);
            }

            DB::table('candidate_meeting_placements')
                ->where('scheduling_candidate_id', $locked->id)
                ->update(['status' => 'published']);

            DB::table('candidate_room_allocations')
                ->whereIn('candidate_meeting_placement_id', function ($query) use ($locked): void {
                    $query->select('id')
                        ->from('candidate_meeting_placements')
                        ->where('scheduling_candidate_id', $locked->id);
                })
                ->update(['status' => 'published']);

            $this->updateCandidate($locked, 'published', [
                'published_by_user_id' => $this->userId($user),
                'published_at' => now(),
            ]);
            $this->recordCandidateEvent($locked, 'approved', 'published', $user, $reason, [
                'event_type' => 'candidate_published',
                'publication_uuid' => $publication->uuid,
                'created_schedule_count' => $createdCount,
                'superseded_schedule_count' => $supersededCount,
            ]);

            return [
                'publication' => $this->publicationSummary($publication->load('schedules')),
                'candidate' => $locked->fresh(),
                'created_schedule_count' => $createdCount,
                'superseded_schedule_count' => $supersededCount,
                'idempotent' => false,
            ];
        }, attempts: 3);
    }

    public function publications(int $termId): array
    {
        AcademicTerm::query()->findOrFail($termId);

        return TimetablePublication::query()
            ->where('academic_term_id', $termId)
            ->withCount('schedules')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TimetablePublication $publication): array => $this->publicationSummary($publication))
            ->all();
    }

    public function activePublication(int $termId): ?array
    {
        $publication = $this->activePublicationForTerm($termId);

        return $publication === null ? null : $this->publicationSummary($publication->loadCount('schedules'));
    }

    public function candidateStatusEvents(SchedulingCandidate $candidate): array
    {
        return $candidate->statusEvents()
            ->with('actor')
            ->get()
            ->map(fn (CandidateStatusEvent $event): array => [
                'id' => $event->id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'actor_user_id' => $event->actor_user_id,
                'reason' => $event->reason,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function transition(
        SchedulingCandidate $candidate,
        array $allowedStatuses,
        string $toStatus,
        int $expectedVersion,
        ?Authenticatable $user,
        string $reason,
    ): SchedulingCandidate {
        return DB::transaction(function () use ($candidate, $allowedStatuses, $toStatus, $expectedVersion, $user, $reason): SchedulingCandidate {
            $term = AcademicTerm::query()->whereKey($candidate->academic_term_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockCandidate($candidate);
            $this->assertVersion($locked, $expectedVersion);
            $generation = SchedulingGeneration::query()->whereKey($locked->scheduling_generation_id)->lockForUpdate()->firstOrFail();
            $this->assertSourceIsCurrent($locked, $generation, $term);

            if (! in_array($locked->status, $allowedStatuses, true)) {
                throw new InvalidSchedulingCandidateTransitionException(
                    "Cannot change a candidate from {$locked->status} to {$toStatus}.",
                );
            }

            $attributes = [];

            if ($toStatus === 'approved') {
                $attributes['approved_by_user_id'] = $this->userId($user);
                $attributes['approved_at'] = now();
                $attributes['rejected_by_user_id'] = null;
                $attributes['rejected_at'] = null;
                $attributes['rejection_reason'] = null;
            }

            if ($toStatus === 'rejected') {
                $attributes['rejected_by_user_id'] = $this->userId($user);
                $attributes['rejected_at'] = now();
                $attributes['rejection_reason'] = $reason;
                $attributes['approved_by_user_id'] = null;
                $attributes['approved_at'] = null;
            }

            if (in_array($toStatus, self::VALIDATION_BEFORE_STATUS, true)) {
                $this->revisions->publicationContent($locked);
            }

            $fromStatus = $locked->status;
            $this->updateCandidate($locked, $toStatus, $attributes);
            $this->recordCandidateEvent($locked, $fromStatus, $toStatus, $user, $reason, [
                'event_type' => 'candidate_status_transition',
            ]);

            return $locked->fresh();
        }, attempts: 3);
    }

    private function assertSourceIsCurrent(
        SchedulingCandidate $candidate,
        SchedulingGeneration $generation,
        AcademicTerm $term,
    ): void {
        if ($candidate->snapshot_sha256 !== $generation->snapshot?->content_sha256) {
            throw new StaleSchedulingCandidateException('The candidate snapshot no longer matches its generation snapshot.');
        }

        $build = $this->requests->build(
            $term,
            $generation->correlation_id,
            $generation->uuid,
        );

        if (! $build->isSuccessful()) {
            $codes = array_column($build->issues(), 'code');
            throw new StaleSchedulingCandidateException(
                'Authoritative scheduling data changed after this candidate was generated: '.implode(', ', $codes).'.',
            );
        }

        if ($build->payload()['metadata']['snapshot_sha256'] !== $candidate->snapshot_sha256) {
            throw new StaleSchedulingCandidateException('Authoritative scheduling data changed after this candidate was generated.');
        }
    }

    private function publishAssignment(
        SchedulingCandidate $candidate,
        TimetablePublication $publication,
        AcademicTerm $term,
        mixed $assignment,
        ?Authenticatable $user,
    ): int {
        if (! is_array($assignment)) {
            throw new InvalidArgumentException('The candidate contains an invalid placement.');
        }

        $meetingInstanceId = (string) ($assignment['meeting_instance_id'] ?? '');
        $placement = DB::table('candidate_meeting_placements')
            ->where('scheduling_candidate_id', $candidate->id)
            ->where('meeting_instance_id', $meetingInstanceId)
            ->first();

        if ($placement === null) {
            throw new InvalidArgumentException("Candidate placement {$meetingInstanceId} could not be found.");
        }

        $offeringIds = array_values(array_unique(array_map('strval', is_array($assignment['subject_offering_ids'] ?? null)
            ? $assignment['subject_offering_ids']
            : [])));
        $professorAssignments = array_values(array_filter(is_array($assignment['professor_assignments'] ?? null)
            ? $assignment['professor_assignments']
            : [], fn ($row): bool => is_array($row)));
        $enrollments = SubjectOffering::query()
            ->whereKey(array_map('intval', $offeringIds))
            ->pluck('expected_students')
            ->all();
        if (count($enrollments) !== count($offeringIds)
            || (($assignment['modality'] ?? null) !== 'online' && in_array(null, $enrollments, true))) {
            throw new InvalidArgumentException("Combined offering enrollment for placement {$meetingInstanceId} is incomplete.");
        }
        $totalEnrollment = array_sum(array_map('intval', $enrollments));
        $created = 0;

        foreach ($offeringIds as $offeringId) {
            $offering = SubjectOffering::query()->with([
                'academicTerm',
                'section.program',
                'subject',
            ])->lockForUpdate()->findOrFail((int) $offeringId);

            $this->assertOfferingSchedulable($offering, $term);

            $schedule = ClassSchedule::query()->forceCreate([
                'subject_offering_id' => $offering->id,
                'day_of_week' => (int) $assignment['day_of_week'],
                'start_time' => (string) $assignment['start_time'],
                'end_time' => (string) $assignment['end_time'],
                'schedule_type' => (string) $assignment['meeting_type'],
                'modality' => (string) $assignment['modality'],
                'status' => 'published',
                'timetable_publication_id' => $publication->id,
                'candidate_meeting_placement_id' => $placement->id,
                'meeting_instance_id' => $meetingInstanceId,
            ]);

            $matchingProfessors = array_values(array_filter(
                $professorAssignments,
                fn (array $row): bool => (string) ($row['subject_offering_id'] ?? '') === (string) $offering->id,
            ));
            $hasLead = collect($matchingProfessors)->contains(
                fn (array $row): bool => ($row['assignment_role'] ?? null) === 'lead',
            );

            if ($matchingProfessors === []) {
                throw new InvalidArgumentException("No professor assignment was provided for offering {$offering->id}.");
            }

            foreach ($matchingProfessors as $index => $professorAssignment) {
                $facultyAssignment = FacultyAssignment::query()->with('professor.user')
                    ->lockForUpdate()
                    ->findOrFail((int) ($professorAssignment['faculty_assignment_id'] ?? 0));

                if ($facultyAssignment->status !== 'active'
                    || $facultyAssignment->subject_offering_id !== $offering->id
                    || $facultyAssignment->professor === null
                    || $facultyAssignment->professor->status !== 'active'
                    || $facultyAssignment->professor->user?->status !== 'active') {
                    throw new InvalidArgumentException("Faculty assignment {$facultyAssignment->id} is no longer eligible for publication.");
                }

                ScheduleAssignment::query()->forceCreate([
                    'class_schedule_id' => $schedule->id,
                    'faculty_assignment_id' => $facultyAssignment->id,
                    'is_primary' => ($professorAssignment['assignment_role'] ?? null) === 'lead'
                        || (! $hasLead && $index === 0),
                    'status' => 'active',
                ]);
            }

            $roomId = $assignment['room_id'] ?? null;
            if ($roomId !== null && in_array($schedule->modality, ['face_to_face', 'hybrid'], true)) {
                $room = Room::query()->lockForUpdate()->findOrFail((int) $roomId);
                if ($room->status !== 'available') {
                    throw new InvalidArgumentException("Room {$room->id} is not available for publication.");
                }
                if ($room->capacity < $totalEnrollment) {
                    throw new InvalidArgumentException("Room {$room->id} does not fit combined enrollment for placement {$meetingInstanceId}.");
                }

                RoomAllocation::query()->forceCreate([
                    'class_schedule_id' => $schedule->id,
                    'room_id' => $room->id,
                    'status' => 'active',
                    'allocated_at' => now(),
                ]);
            }

            $this->recordClassScheduleEvent(
                $schedule,
                $term->id,
                $publication->id,
                $placement->id,
                'published',
                null,
                'published',
                $user,
                'Published from scheduling candidate.',
            );
            $created += 1;
        }

        return $created;
    }

    private function supersedeActiveTimetable(
        AcademicTerm $term,
        ?TimetablePublication $publication,
        ?Authenticatable $user,
        string $reason,
    ): int {
        $schedules = ClassSchedule::query()
            ->where('status', 'published')
            ->whereHas('subjectOffering', fn ($query) => $query->where('academic_term_id', $term->id))
            ->lockForUpdate()
            ->get();

        foreach ($schedules as $schedule) {
            $schedule->forceFill([
                'status' => 'superseded',
                'superseded_at' => now(),
            ])->save();

            $schedule->scheduleAssignments()->where('status', 'active')->update(['status' => 'superseded']);
            $schedule->roomAllocations()->where('status', 'active')->update([
                'status' => 'superseded',
                'released_at' => now(),
            ]);

            $this->recordClassScheduleEvent(
                $schedule,
                $term->id,
                $publication?->id,
                null,
                'superseded',
                'published',
                'superseded',
                $user,
                $reason !== '' ? $reason : 'Superseded by candidate timetable publication.',
            );
        }

        return $schedules->count();
    }

    private function assertOfferingSchedulable(SubjectOffering $offering, AcademicTerm $term): void
    {
        if ($offering->status !== 'open'
            || $offering->section?->status !== 'active'
            || $offering->subject?->status !== 'active'
            || $offering->section?->program?->status !== 'active'
            || ! in_array($term->status, ['active', 'planned'], true)
            || $offering->academic_term_id !== $term->id
            || $offering->section->academic_term_id !== $term->id) {
            throw new InvalidArgumentException("Offering {$offering->id} is no longer schedulable.");
        }
    }

    private function lockCandidate(SchedulingCandidate $candidate): SchedulingCandidate
    {
        return SchedulingCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
    }

    private function assertVersion(SchedulingCandidate $candidate, int $expectedVersion): void
    {
        if ($candidate->version !== $expectedVersion) {
            throw new StaleSchedulingCandidateException(
                "Candidate version {$expectedVersion} is stale; current version is {$candidate->version}.",
            );
        }
    }

    private function updateCandidate(SchedulingCandidate $candidate, string $status, array $attributes): void
    {
        $updated = DB::table('scheduling_candidates')
            ->where('id', $candidate->id)
            ->where('version', $candidate->version)
            ->update([
                'status' => $status,
                ...$attributes,
                'version' => $candidate->version + 1,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new StaleSchedulingCandidateException('The scheduling candidate changed while it was being updated.');
        }

        $candidate->forceFill(['status' => $status, ...$attributes, 'version' => $candidate->version + 1]);
    }

    private function recordCandidateEvent(
        SchedulingCandidate $candidate,
        ?string $fromStatus,
        string $toStatus,
        ?Authenticatable $user,
        string $reason,
        array $metadata,
    ): void {
        CandidateStatusEvent::query()->forceCreate([
            'scheduling_candidate_id' => $candidate->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $this->userId($user),
            'reason' => $reason !== '' ? $reason : null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function recordClassScheduleEvent(
        ClassSchedule $schedule,
        int $termId,
        ?int $publicationId,
        ?int $placementId,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        ?Authenticatable $user,
        string $reason,
    ): void {
        ClassScheduleEvent::query()->forceCreate([
            'class_schedule_id' => $schedule->id,
            'academic_term_id' => $termId,
            'timetable_publication_id' => $publicationId,
            'candidate_meeting_placement_id' => $placementId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $this->userId($user),
            'reason' => $reason,
            'metadata' => [],
            'created_at' => now(),
        ]);
    }

    private function activePublicationForTerm(int $termId): ?TimetablePublication
    {
        return TimetablePublication::query()
            ->where('academic_term_id', $termId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();
    }

    private function publicationSummary(TimetablePublication $publication): array
    {
        return [
            'uuid' => $publication->uuid,
            'academic_term_id' => $publication->academic_term_id,
            'candidate_uuid' => $publication->candidate?->uuid,
            'publication_type' => $publication->publication_type,
            'status' => $publication->status,
            'published_at' => $publication->published_at?->toIso8601String(),
            'superseded_at' => $publication->superseded_at?->toIso8601String(),
            'schedule_count' => $publication->schedules_count ?? $publication->schedules()->count(),
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
}

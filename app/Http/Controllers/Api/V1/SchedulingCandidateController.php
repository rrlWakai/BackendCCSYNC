<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidCandidateRevisionTransitionException;
use App\Exceptions\StaleCandidateRevisionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\StoreCandidateRevisionRequest;
use App\Http\Requests\Scheduling\TransitionCandidateRevisionRequest;
use App\Http\Requests\Scheduling\UpdateCandidateRevisionRequest;
use App\Http\Requests\Scheduling\ValidateCandidateRevisionRequest;
use App\Models\ClassSchedule;
use App\Models\SchedulingCandidate;
use App\Models\SchedulingCandidateRevision;
use App\Services\Scheduling\SchedulingCandidateRevisionService;
use App\Services\Scheduling\SchedulingGenerationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SchedulingCandidateController extends Controller
{
    public function __construct(
        private readonly SchedulingCandidateRevisionService $revisions,
        private readonly SchedulingGenerationService $generations,
    ) {}

    public function show(SchedulingCandidate $candidate): JsonResponse
    {
        Gate::authorize('viewAny', ClassSchedule::class);

        $candidate->loadMissing('revisions.creator');

        return ApiResponse::success([
            'candidate' => $this->generations->candidateSummary($candidate),
            'revisions' => $candidate->revisions
                ->sortBy('revision_number')
                ->values()
                ->map(fn (SchedulingCandidateRevision $revision): array => $this->revisions->summary($revision))
                ->all(),
        ], 'Scheduling candidate loaded.');
    }

    public function storeRevision(
        StoreCandidateRevisionRequest $request,
        SchedulingCandidate $candidate,
    ): JsonResponse {
        $changeSummary = (string) $request->validated('change_summary');
        $parentRevisionUuid = $request->validated('parent_revision_uuid');

        try {
            if (is_string($parentRevisionUuid) && $parentRevisionUuid !== '') {
                $parent = SchedulingCandidateRevision::query()->where('uuid', $parentRevisionUuid)->firstOrFail();

                if ($parent->scheduling_candidate_id !== $candidate->id) {
                    return ApiResponse::error(
                        'The parent revision belongs to another candidate.',
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                        ['parent_revision_uuid' => ['The selected revision does not belong to this candidate.']],
                        'candidate_revision_mismatch',
                    );
                }

                $revision = $this->revisions->createFromRevision($parent, $request->user(), $changeSummary);
            } else {
                $revision = $this->revisions->createFromCandidate($candidate, $request->user(), $changeSummary);
            }
        } catch (Throwable $exception) {
            return $this->revisionFailure($exception);
        }

        return ApiResponse::success(
            ['revision' => $this->revisions->summary($revision)],
            'Scheduling candidate revision created.',
            Response::HTTP_CREATED,
        );
    }

    public function showRevision(SchedulingCandidateRevision $revision): JsonResponse
    {
        Gate::authorize('viewAny', ClassSchedule::class);

        $revision->loadMissing('candidate', 'parent', 'creator', 'updater', 'validator', 'statusEvents.actor');

        return ApiResponse::success([
            'revision' => $this->revisions->summary($revision),
            'candidate' => $this->generations->candidateSummary($revision->candidate),
            'content' => $this->content($revision),
            'status_events' => $revision->statusEvents
                ->map(fn ($event): array => $this->revisions->eventSummary($event))
                ->all(),
        ], 'Scheduling candidate revision loaded.');
    }

    public function updateRevision(
        UpdateCandidateRevisionRequest $request,
        SchedulingCandidateRevision $revision,
    ): JsonResponse {
        $changes = array_intersect_key(
            (array) $request->validated('changes'),
            array_flip(['validation_summary', 'assignments']),
        );

        try {
            $revision = $this->revisions->updateContent(
                $revision,
                (int) $request->validated('expected_version'),
                $changes,
                $request->user(),
                (string) $request->validated('change_summary'),
            );
        } catch (Throwable $exception) {
            return $this->revisionFailure($exception);
        }

        return ApiResponse::success(
            ['revision' => $this->revisions->summary($revision)],
            'Scheduling candidate revision updated.',
        );
    }

    public function validateRevision(
        ValidateCandidateRevisionRequest $request,
        SchedulingCandidateRevision $revision,
    ): JsonResponse {
        try {
            $result = $this->revisions->validate(
                $revision,
                (int) $request->validated('expected_version'),
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (Throwable $exception) {
            return $this->revisionFailure($exception);
        }

        return ApiResponse::success([
            'revision' => $this->revisions->summary($result['revision']),
            'errors' => $result['errors'],
        ], 'Scheduling candidate revision revalidated.');
    }

    public function transition(
        TransitionCandidateRevisionRequest $request,
        SchedulingCandidateRevision $revision,
    ): JsonResponse {
        try {
            $revision = $this->revisions->transition(
                $revision,
                (int) $request->validated('expected_version'),
                (string) $request->validated('to_status'),
                (string) $request->validated('reason'),
                $request->user(),
            );
        } catch (Throwable $exception) {
            return $this->revisionFailure($exception);
        }

        return ApiResponse::success(
            ['revision' => $this->revisions->summary($revision)],
            'Scheduling candidate revision status changed.',
        );
    }

    public function statusEvents(SchedulingCandidateRevision $revision): JsonResponse
    {
        Gate::authorize('viewAny', ClassSchedule::class);

        $revision->loadMissing('statusEvents.actor');

        return ApiResponse::success([
            'status_events' => $revision->statusEvents
                ->map(fn ($event): array => $this->revisions->eventSummary($event))
                ->all(),
        ], 'Scheduling candidate revision history loaded.');
    }

    private function content(SchedulingCandidateRevision $revision): array
    {
        $decoded = json_decode($revision->content_canonical, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function revisionFailure(Throwable $exception): JsonResponse
    {
        if ($exception instanceof StaleCandidateRevisionException) {
            return ApiResponse::error(
                $exception->getMessage(),
                Response::HTTP_CONFLICT,
                null,
                'stale_candidate_revision',
            );
        }

        if ($exception instanceof InvalidCandidateRevisionTransitionException) {
            return ApiResponse::error(
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                null,
                'invalid_candidate_revision_transition',
            );
        }

        if ($exception instanceof InvalidArgumentException) {
            return ApiResponse::error(
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                null,
                'invalid_candidate_revision_content',
            );
        }

        throw $exception;
    }
}

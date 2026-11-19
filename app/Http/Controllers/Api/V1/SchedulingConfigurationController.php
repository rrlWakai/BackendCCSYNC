<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\SchedulingConfigurationRequest;
use App\Http\Requests\Scheduling\SchedulingFullTimeWorkdayPolicyRequest;
use App\Http\Requests\Scheduling\SchedulingMeetingPlanRequest;
use App\Http\Requests\Scheduling\SchedulingTimeGridRequest;
use App\Models\AcademicTerm;
use App\Models\SchedulingFullTimeWorkdayPolicy;
use App\Models\SchedulingMeetingPlan;
use App\Models\SchedulingTimeGrid;
use App\Models\SubjectOffering;
use App\Services\Scheduling\SchedulingConfigurationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SchedulingConfigurationController extends Controller
{
    public function __construct(private readonly SchedulingConfigurationService $configuration) {}

    public function timeGrid(SchedulingConfigurationRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SchedulingTimeGrid::class);

        $term = AcademicTerm::query()->findOrFail($request->validated('academic_term_id'));

        return ApiResponse::success(
            ['configuration' => $this->configuration->timeGridIndex($term)],
            'Scheduling time grid loaded.',
        );
    }

    public function storeTimeGrid(SchedulingTimeGridRequest $request): JsonResponse
    {
        Gate::authorize('create', SchedulingTimeGrid::class);

        $term = AcademicTerm::query()->findOrFail($request->validated('academic_term_id'));
        $grid = $this->configuration->createTimeGridDraft($term, $request->validated(), $request->user());

        return ApiResponse::success(
            ['time_grid' => $this->configuration->presentTimeGrid($grid)],
            'Time grid draft created.',
            201,
        );
    }

    public function updateTimeGrid(SchedulingTimeGridRequest $request, SchedulingTimeGrid $timeGrid): JsonResponse
    {
        Gate::authorize('update', $timeGrid);

        $grid = $this->configuration->updateTimeGridDraft($timeGrid, $request->validated());

        return ApiResponse::success(
            ['time_grid' => $this->configuration->presentTimeGrid($grid)],
            'Time grid draft updated.',
        );
    }

    public function destroyTimeGrid(SchedulingTimeGrid $timeGrid): JsonResponse
    {
        Gate::authorize('delete', $timeGrid);
        $this->configuration->deleteTimeGridDraft($timeGrid);

        return ApiResponse::success(null, 'Time grid draft discarded.');
    }

    public function approveTimeGrid(Request $request, SchedulingTimeGrid $timeGrid): JsonResponse
    {
        Gate::authorize('approve', $timeGrid);

        $grid = $this->configuration->approveTimeGrid($timeGrid, $request->user());

        return ApiResponse::success(
            ['time_grid' => $this->configuration->presentTimeGrid($grid)],
            'Time grid approved.',
        );
    }

    public function reviseTimeGrid(SchedulingTimeGrid $timeGrid): JsonResponse
    {
        Gate::authorize('revise', $timeGrid);

        $grid = $this->configuration->reviseTimeGrid($timeGrid);

        return ApiResponse::success(
            ['time_grid' => $this->configuration->presentTimeGrid($grid)],
            'Time grid revision created.',
            201,
        );
    }

    public function fullTimePolicies(SchedulingConfigurationRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SchedulingFullTimeWorkdayPolicy::class);

        $term = AcademicTerm::query()->findOrFail($request->validated('academic_term_id'));

        return ApiResponse::success(
            ['configuration' => $this->configuration->fullTimePolicyIndex($term)],
            'Full-time workday policy loaded.',
        );
    }

    public function storeFullTimePolicy(SchedulingFullTimeWorkdayPolicyRequest $request): JsonResponse
    {
        Gate::authorize('create', SchedulingFullTimeWorkdayPolicy::class);

        $term = AcademicTerm::query()->findOrFail($request->validated('academic_term_id'));
        $policy = $this->configuration->createFullTimePolicyDraft($term, $request->validated(), $request->user());

        return ApiResponse::success(
            ['full_time_policy' => $this->configuration->presentFullTimePolicy($policy)],
            'Full-time workday policy draft created.',
            201,
        );
    }

    public function updateFullTimePolicy(
        SchedulingFullTimeWorkdayPolicyRequest $request,
        SchedulingFullTimeWorkdayPolicy $fullTimePolicy,
    ): JsonResponse {
        Gate::authorize('update', $fullTimePolicy);

        if ((int) $request->validated('academic_term_id') !== (int) $fullTimePolicy->academic_term_id) {
            throw ValidationException::withMessages([
                'academic_term_id' => 'The policy belongs to a different academic term.',
            ]);
        }

        $policy = $this->configuration->updateFullTimePolicyDraft($fullTimePolicy, $request->validated());

        return ApiResponse::success(
            ['full_time_policy' => $this->configuration->presentFullTimePolicy($policy)],
            'Full-time workday policy draft updated.',
        );
    }

    public function destroyFullTimePolicy(SchedulingFullTimeWorkdayPolicy $fullTimePolicy): JsonResponse
    {
        Gate::authorize('delete', $fullTimePolicy);
        $this->configuration->deleteFullTimePolicyDraft($fullTimePolicy);

        return ApiResponse::success(null, 'Full-time workday policy draft discarded.');
    }

    public function approveFullTimePolicy(
        Request $request,
        SchedulingFullTimeWorkdayPolicy $fullTimePolicy,
    ): JsonResponse {
        Gate::authorize('approve', $fullTimePolicy);

        $policy = $this->configuration->approveFullTimePolicy($fullTimePolicy, $request->user());

        return ApiResponse::success(
            ['full_time_policy' => $this->configuration->presentFullTimePolicy($policy)],
            'Full-time workday policy approved.',
        );
    }

    public function reviseFullTimePolicy(SchedulingFullTimeWorkdayPolicy $fullTimePolicy): JsonResponse
    {
        Gate::authorize('revise', $fullTimePolicy);

        $policy = $this->configuration->reviseFullTimePolicy($fullTimePolicy);

        return ApiResponse::success(
            ['full_time_policy' => $this->configuration->presentFullTimePolicy($policy)],
            'Full-time workday policy revision created.',
            201,
        );
    }

    public function meetingPlans(SchedulingConfigurationRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SchedulingMeetingPlan::class);

        $term = AcademicTerm::query()->findOrFail($request->validated('academic_term_id'));

        return ApiResponse::success(
            $this->configuration->meetingPlanIndex($term),
            'Scheduling meeting plans loaded.',
        );
    }

    public function storeMeetingPlan(SchedulingMeetingPlanRequest $request): JsonResponse
    {
        Gate::authorize('create', SchedulingMeetingPlan::class);

        $offering = SubjectOffering::query()
            ->with('academicTerm')
            ->findOrFail($request->validated('subject_offering_id'));

        if ($offering->status !== 'open') {
            throw ValidationException::withMessages([
                'subject_offering_id' => 'Meeting plans can only be configured for open class assignments.',
            ]);
        }

        $plan = $this->configuration->createMeetingPlanDraft($offering, $request->validated(), $request->user());

        return ApiResponse::success(
            ['meeting_plan' => $this->configuration->presentMeetingPlan($plan)],
            'Meeting plan draft created.',
            201,
        );
    }

    public function updateMeetingPlan(
        SchedulingMeetingPlanRequest $request,
        SchedulingMeetingPlan $meetingPlan,
    ): JsonResponse {
        Gate::authorize('update', $meetingPlan);

        if ((int) $request->validated('subject_offering_id') !== (int) $meetingPlan->subject_offering_id) {
            throw ValidationException::withMessages([
                'subject_offering_id' => 'The meeting plan belongs to a different class assignment.',
            ]);
        }

        $plan = $this->configuration->updateMeetingPlanDraft($meetingPlan, $request->validated());

        return ApiResponse::success(
            ['meeting_plan' => $this->configuration->presentMeetingPlan($plan)],
            'Meeting plan draft updated.',
        );
    }

    public function destroyMeetingPlan(SchedulingMeetingPlan $meetingPlan): JsonResponse
    {
        Gate::authorize('delete', $meetingPlan);
        $this->configuration->deleteMeetingPlanDraft($meetingPlan);

        return ApiResponse::success(null, 'Meeting plan draft discarded.');
    }

    public function approveMeetingPlan(Request $request, SchedulingMeetingPlan $meetingPlan): JsonResponse
    {
        Gate::authorize('approve', $meetingPlan);

        $plan = $this->configuration->approveMeetingPlan($meetingPlan, $request->user());

        return ApiResponse::success(
            ['meeting_plan' => $this->configuration->presentMeetingPlan($plan)],
            'Meeting plan approved.',
        );
    }

    public function reviseMeetingPlan(SchedulingMeetingPlan $meetingPlan): JsonResponse
    {
        Gate::authorize('revise', $meetingPlan);

        $plan = $this->configuration->reviseMeetingPlan($meetingPlan);

        return ApiResponse::success(
            ['meeting_plan' => $this->configuration->presentMeetingPlan($plan)],
            'Meeting plan revision created.',
            201,
        );
    }
}

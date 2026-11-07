<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activities\CancelActivityRequest;
use App\Http\Requests\Activities\IndexActivitiesRequest;
use App\Http\Requests\Activities\IndexActivityHistoryRequest;
use App\Http\Requests\Activities\IndexActivityOptionsRequest;
use App\Http\Requests\Activities\IndexManagedActivitiesRequest;
use App\Http\Requests\Activities\StoreActivityRequest;
use App\Http\Requests\Activities\UpdateActivityRequest;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Role;
use App\Models\Room;
use App\Models\Section;
use App\Models\User;
use App\Models\YearLevel;
use App\Services\Activities\ActivityManagementService;
use App\Support\ActivityPresenter;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class ActivityController extends Controller
{
    public function __construct(private readonly ActivityManagementService $activities) {}

    public function manage(IndexManagedActivitiesRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->activities->managePage($request->validated(), $request->user()),
            'Activities loaded.',
        );
    }

    public function options(IndexActivityOptionsRequest $request): JsonResponse
    {
        $actor = $request->user();
        $assignedProgramIds = $actor->hasRole(Role::DEPARTMENT_PERSONNEL)
            ? $actor->departmentPersonnelPrograms()->pluck('programs.id')->map(fn ($id): int => (int) $id)->all()
            : null;
        $programs = Program::query()
            ->when($assignedProgramIds !== null, fn (Builder $query) => $query->whereIn('id', $assignedProgramIds))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
        $sections = Section::query()
            ->with(['program:id,code,name', 'yearLevel:id,level,name'])
            ->when($assignedProgramIds !== null, fn (Builder $query) => $query->whereIn('program_id', $assignedProgramIds))
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['id', 'program_id', 'year_level_id', 'code', 'name'])
            ->map(fn (Section $section): array => [
                'id' => $section->id,
                'program_id' => $section->program_id,
                'year_level_id' => $section->year_level_id,
                'code' => $section->code,
                'name' => $section->name ?? $section->code,
            ]);

        $userQuery = User::query()
            ->with('role:id,code')
            ->where('status', 'active')
            ->whereHas('role', fn (Builder $query) => $query->whereIn('code', [
                Role::STUDENT,
                Role::PROFESSOR,
            ]));

        if ($assignedProgramIds !== null) {
            $userQuery->where(function (Builder $query) use ($assignedProgramIds): void {
                $query->whereHas('student', fn (Builder $student) => $student->whereIn('program_id', $assignedProgramIds))
                    ->orWhereHas('professor', fn (Builder $professor) => $professor->whereIn('program_id', $assignedProgramIds));
            });
        }

        $search = trim((string) ($request->validated('search') ?? ''));
        if ($search !== '') {
            $userQuery->where('name', 'like', '%'.$search.'%');
        }

        return ApiResponse::success([
            'roles' => Role::query()
                ->whereIn('code', [Role::STUDENT, Role::PROFESSOR])
                ->orderBy('id')
                ->get(['id', 'code', 'name']),
            'programs' => $programs,
            'year_levels' => YearLevel::query()->orderBy('level')->get(['id', 'level', 'name']),
            'sections' => $sections,
            'users' => $userQuery
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'role_id'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role?->code,
                ])
                ->all(),
            'rooms' => Room::query()
                ->orderBy('building')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'building', 'floor', 'status']),
        ], 'Activity options loaded.');
    }

    public function store(StoreActivityRequest $request): JsonResponse
    {
        $activity = $this->activities->create($request->validated(), $request->user());

        return ApiResponse::success(
            ['activity' => ActivityPresenter::activity($activity, true)],
            'Activity draft created.',
            201,
        );
    }

    public function update(UpdateActivityRequest $request, Activity $activity): JsonResponse
    {
        $activity = $this->activities->update($activity, $request->validated(), $request->user());

        return ApiResponse::success(
            ['activity' => ActivityPresenter::activity($activity, true)],
            'Activity updated.',
        );
    }

    public function publish(Activity $activity): JsonResponse
    {
        Gate::authorize('publish', $activity);
        $activity = $this->activities->publish($activity, request()->user());

        return ApiResponse::success(
            ['activity' => ActivityPresenter::activity($activity, true)],
            'Activity published.',
        );
    }

    public function cancel(CancelActivityRequest $request, Activity $activity): JsonResponse
    {
        Gate::authorize('cancel', $activity);
        $activity = $this->activities->cancel(
            $activity,
            $request->validated('reason'),
            $request->user(),
        );

        return ApiResponse::success(
            ['activity' => ActivityPresenter::activity($activity, true)],
            'Activity cancelled.',
        );
    }

    public function complete(Activity $activity): JsonResponse
    {
        Gate::authorize('complete', $activity);
        $activity = $this->activities->complete($activity, request()->user());

        return ApiResponse::success(
            ['activity' => ActivityPresenter::activity($activity, true)],
            'Activity marked completed.',
        );
    }

    public function history(IndexActivityHistoryRequest $request, Activity $activity): JsonResponse
    {
        Gate::authorize('viewHistory', $activity);

        return ApiResponse::success(
            $this->activities->historyPage($activity, $request->validated()),
            'Activity history loaded.',
        );
    }

    public function index(IndexActivitiesRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Activity::class);

        return ApiResponse::success(
            $this->activities->feedPage($request->validated(), $request->user()),
            'Activities loaded.',
        );
    }

    public function show(Activity $activity): JsonResponse
    {
        Gate::authorize('view', $activity);
        $manager = request()->user()->can('manage', Activity::class);

        return ApiResponse::success(
            ['activity' => ActivityPresenter::activity($activity, $manager)],
            'Activity loaded.',
        );
    }
}

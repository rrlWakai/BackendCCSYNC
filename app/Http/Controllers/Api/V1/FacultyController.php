<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faculty\IndexFacultyRequest;
use App\Http\Requests\Faculty\StoreFacultyAvailabilityRequest;
use App\Http\Requests\Faculty\UpdateFacultyAvailabilityRequest;
use App\Http\Requests\Faculty\UpdateOwnProfessorRequest;
use App\Models\FacultyAssignment;
use App\Models\FacultyAvailability;
use App\Models\Professor;
use App\Models\Role;
use App\Support\ApiResponse;
use App\Support\ProfessorPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FacultyController extends Controller
{
    public function index(IndexFacultyRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = Professor::query()
            ->select('professors.*')
            ->join('users', 'users.id', '=', 'professors.user_id')
            ->with(['user:id,name,email,status,created_at', 'program:id,code,name,status'])
            ->whereHas('user.role', fn ($roleQuery) => $roleQuery->where('code', Role::PROFESSOR));

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('professors.employee_number', 'like', '%'.$search.'%')
                    ->orWhere('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['status'])) {
            $query->where('users.status', $filters['status']);
        }

        $professors = $query
            ->orderBy('users.name')
            ->orderBy('professors.id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'faculty' => collect($professors->items())->map(ProfessorPresenter::toDirectoryArray(...))->all(),
            'pagination' => [
                'current_page' => $professors->currentPage(),
                'last_page' => $professors->lastPage(),
                'per_page' => $professors->perPage(),
                'total' => $professors->total(),
            ],
        ], 'Faculty loaded.');
    }

    public function me(Request $request): JsonResponse
    {
        $professor = $request->user()?->professor;

        if ($professor === null) {
            throw new NotFoundHttpException('Professor profile not found.');
        }

        Gate::authorize('view', $professor);

        return $this->detail($professor);
    }

    public function show(Professor $professor): JsonResponse
    {
        Gate::authorize('view', $professor);

        return $this->detail($professor);
    }

    public function updateMe(UpdateOwnProfessorRequest $request): JsonResponse
    {
        $professor = $request->user()?->professor;

        if ($professor === null) {
            throw new NotFoundHttpException('Professor profile not found.');
        }

        $professor->update($request->validated());
        $professor = $professor->fresh();

        return ApiResponse::success(
            ['professor' => ProfessorPresenter::toDetailArray($professor)],
            'Professor profile updated.',
        );
    }

    public function assignments(Professor $professor): JsonResponse
    {
        Gate::authorize('view', $professor);

        return $this->assignmentsFor($professor);
    }

    public function assignmentsForCurrentUser(Request $request): JsonResponse
    {
        $professor = $request->user()?->professor;

        if ($professor === null) {
            throw new NotFoundHttpException('Professor profile not found.');
        }

        Gate::authorize('view', $professor);

        return $this->assignmentsFor($professor);
    }

    private function assignmentsFor(Professor $professor): JsonResponse
    {
        $assignments = $professor->facultyAssignments()
            ->with([
                'subjectOffering.subject:id,code,title,units',
                'subjectOffering.section:id,program_id,year_level_id,academic_term_id,code,name',
                'subjectOffering.section.program:id,code,name',
                'subjectOffering.section.yearLevel:id,level,name',
                'subjectOffering.academicTerm:id,academic_year,term,name',
            ])
            ->orderBy('id')
            ->get()
            ->map(ProfessorPresenter::assignmentArray(...))
            ->all();

        return ApiResponse::success(['assignments' => $assignments], 'Faculty assignments loaded.');
    }

    public function availability(Professor $professor): JsonResponse
    {
        Gate::authorize('manageAvailability', $professor);

        $availability = $professor->facultyAvailability()
            ->with('academicTerm:id,academic_year,term,name')
            ->orderBy('academic_term_id')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->map(ProfessorPresenter::availabilityArray(...))
            ->all();

        return ApiResponse::success(['availability' => $availability], 'Faculty availability loaded.');
    }

    public function availabilityForCurrentUser(Request $request): JsonResponse
    {
        $professor = $request->user()?->professor;

        if ($professor === null) {
            throw new NotFoundHttpException('Professor profile not found.');
        }

        return $this->availability($professor);
    }

    public function storeAvailability(
        StoreFacultyAvailabilityRequest $request,
        Professor $professor,
    ): JsonResponse {
        $validated = $request->validated();
        $isBatch = array_key_exists('windows', $validated);
        $submittedWindows = $isBatch ? $validated['windows'] : [$validated];
        $windows = array_map(
            fn (array $window): array => [
                'academic_term_id' => (int) $validated['academic_term_id'],
                'day_of_week' => (int) $window['day_of_week'],
                'start_time' => $window['start_time'],
                'end_time' => $window['end_time'],
                'availability_type' => $window['availability_type'],
                'notes' => $window['notes'] ?? null,
            ],
            $submittedWindows,
        );

        $availability = DB::transaction(function () use ($windows, $isBatch, $professor) {
            $lockedProfessor = Professor::query()->lockForUpdate()->findOrFail($professor->id);
            if ($isBatch) {
                $this->assertNoBatchOverlap($lockedProfessor, $windows);
            } else {
                $this->assertNoOverlap($lockedProfessor, $windows[0]);
            }
            $this->assertScheduleCompatibilityForWindows($lockedProfessor, $windows);

            return collect($windows)
                ->map(fn (array $attributes): FacultyAvailability => $lockedProfessor
                    ->facultyAvailability()
                    ->create($attributes)
                    ->load('academicTerm:id,academic_year,term,name'));
        });

        $presented = $availability
            ->map(ProfessorPresenter::availabilityArray(...))
            ->all();

        return ApiResponse::success(
            $isBatch
                ? ['availabilities' => $presented]
                : ['availability' => $presented[0]],
            $isBatch ? 'Faculty availability windows created.' : 'Faculty availability created.',
            201,
        );
    }

    public function updateAvailability(
        UpdateFacultyAvailabilityRequest $request,
        FacultyAvailability $availability,
    ): JsonResponse {
        $attributes = $request->validated();
        $attributes['academic_term_id'] = (int) $attributes['academic_term_id'];
        $attributes['day_of_week'] = (int) $attributes['day_of_week'];

        $availability = DB::transaction(function () use ($attributes, $availability): FacultyAvailability {
            $professor = Professor::query()->lockForUpdate()->findOrFail($availability->professor_id);
            $lockedAvailability = $professor->facultyAvailability()
                ->lockForUpdate()
                ->findOrFail($availability->id);

            $this->assertNoOverlap($professor, $attributes, $lockedAvailability->id);
            $this->assertScheduleCompatibility($professor, $attributes, $lockedAvailability);
            $lockedAvailability->update($attributes);

            return $lockedAvailability->fresh();
        });

        $availability->load('academicTerm:id,academic_year,term,name');

        return ApiResponse::success(
            ['availability' => ProfessorPresenter::availabilityArray($availability)],
            'Faculty availability updated.',
        );
    }

    public function destroyAvailability(FacultyAvailability $availability): JsonResponse
    {
        Gate::authorize('manage', $availability);

        DB::transaction(function () use ($availability): void {
            $professor = Professor::query()->lockForUpdate()->findOrFail($availability->professor_id);
            $lockedAvailability = $professor->facultyAvailability()
                ->lockForUpdate()
                ->findOrFail($availability->id);

            $this->assertScheduleCompatibility($professor, null, $lockedAvailability);
            $lockedAvailability->delete();
        });

        return ApiResponse::success([], 'Faculty availability removed.');
    }

    private function detail(Professor $professor): JsonResponse
    {
        $professor->load([
            'user:id,name,email,status,created_at',
            'program:id,code,name,status',
            'facultyAvailability.academicTerm:id,academic_year,term,name',
            'facultyAssignments.subjectOffering.subject:id,code,title,units',
            'facultyAssignments.subjectOffering.section:id,program_id,year_level_id,academic_term_id,code,name',
            'facultyAssignments.subjectOffering.section.program:id,code,name',
            'facultyAssignments.subjectOffering.section.yearLevel:id,level,name',
            'facultyAssignments.subjectOffering.academicTerm:id,academic_year,term,name',
        ]);

        return ApiResponse::success(
            ['professor' => ProfessorPresenter::toDetailArray($professor)],
            'Faculty profile loaded.',
        );
    }

    private function assertNoOverlap(Professor $professor, array $attributes, ?int $ignoreId = null): void
    {
        $overlap = $professor->facultyAvailability()
            ->where('academic_term_id', $attributes['academic_term_id'])
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('start_time', '<', $attributes['end_time'])
            ->where('end_time', '>', $attributes['start_time'])
            ->when($ignoreId !== null, fn (Builder $query) => $query->where('id', '<>', $ignoreId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_time' => 'This availability overlaps another window for the selected term and day.',
            ]);
        }
    }

    private function assertNoBatchOverlap(Professor $professor, array $windows): void
    {
        foreach ($windows as $index => $attributes) {
            $overlap = $professor->facultyAvailability()
                ->where('academic_term_id', $attributes['academic_term_id'])
                ->where('day_of_week', $attributes['day_of_week'])
                ->where('start_time', '<', $attributes['end_time'])
                ->where('end_time', '>', $attributes['start_time'])
                ->exists();

            $overlapsSubmittedWindow = collect(array_slice($windows, 0, $index))
                ->contains(fn (array $window): bool => (int) $window['academic_term_id'] === (int) $attributes['academic_term_id']
                    && (int) $window['day_of_week'] === (int) $attributes['day_of_week']
                    && $window['start_time'] < $attributes['end_time']
                    && $window['end_time'] > $attributes['start_time']);

            if ($overlap || $overlapsSubmittedWindow) {
                throw ValidationException::withMessages([
                    "windows.{$index}.start_time" => 'This availability overlaps another window for the selected term and day.',
                ]);
            }
        }
    }

    private function assertScheduleCompatibility(
        Professor $professor,
        ?array $replacement,
        ?FacultyAvailability $replacing = null,
    ): void {
        $this->assertScheduleCompatibilityForWindows(
            $professor,
            $replacement === null ? [] : [$replacement],
            $replacing,
        );
    }

    private function assertScheduleCompatibilityForWindows(
        Professor $professor,
        array $replacements,
        ?FacultyAvailability $replacing = null,
    ): void {
        $termIds = array_values(array_unique(array_filter(array_merge(
            array_column($replacements, 'academic_term_id'),
            [$replacing?->academic_term_id],
        ))));
        $daysByTerm = [];
        if ($replacing !== null) {
            $daysByTerm[$replacing->academic_term_id][] = $replacing->day_of_week;
        }
        foreach ($replacements as $replacement) {
            $daysByTerm[$replacement['academic_term_id']][] = $replacement['day_of_week'];
        }

        if ($termIds === []) {
            return;
        }

        $windowsByTerm = [];
        foreach ($termIds as $termId) {
            $windowsByTerm[$termId] = $professor->facultyAvailability()
                ->where('academic_term_id', $termId)
                ->when($replacing !== null, fn (Builder $query) => $query->where('id', '<>', $replacing->id))
                ->get()
                ->map(fn (FacultyAvailability $window): array => [
                    'day_of_week' => $window->day_of_week,
                    'start_time' => $window->start_time,
                    'end_time' => $window->end_time,
                    'availability_type' => $window->availability_type,
                ])
                ->all();
        }

        foreach ($replacements as $replacement) {
            $windowsByTerm[$replacement['academic_term_id']][] = [
                'day_of_week' => $replacement['day_of_week'],
                'start_time' => $replacement['start_time'],
                'end_time' => $replacement['end_time'],
                'availability_type' => $replacement['availability_type'],
            ];
        }

        $assignments = FacultyAssignment::query()
            ->where('professor_id', $professor->id)
            ->where('status', 'active')
            ->whereHas('subjectOffering', fn (Builder $query) => $query
                ->whereIn('academic_term_id', $termIds))
            ->with([
                'subjectOffering:id,academic_term_id',
                'scheduleAssignments' => fn (HasMany $query) => $query
                    ->where('status', 'active')
                    ->whereHas('classSchedule', fn (Builder $scheduleQuery) => $scheduleQuery
                        ->where('status', 'published'))
                    ->with('classSchedule'),
            ])
            ->get();

        foreach ($assignments as $assignment) {
            $termId = $assignment->subjectOffering->academic_term_id;
            foreach ($assignment->scheduleAssignments as $scheduleAssignment) {
                $schedule = $scheduleAssignment->classSchedule;
                if (! in_array($schedule->day_of_week, $daysByTerm[$termId] ?? [], true)) {
                    continue;
                }

                $windows = $windowsByTerm[$termId] ?? [];
                $dayWindows = array_values(array_filter(
                    $windows,
                    fn (array $window): bool => $window['day_of_week'] === $schedule->day_of_week,
                ));
                if ($dayWindows === []) {
                    continue;
                }

                $isInAvailableWindow = collect($dayWindows)
                    ->contains(fn (array $window): bool => in_array(
                        $window['availability_type'],
                        ['available', 'preferred'],
                        true,
                    ) && $window['start_time'] <= $schedule->start_time
                        && $window['end_time'] >= $schedule->end_time);
                $overlapsUnavailable = collect($dayWindows)
                    ->contains(fn (array $window): bool => $window['availability_type'] === 'unavailable'
                        && $window['start_time'] < $schedule->end_time
                        && $window['end_time'] > $schedule->start_time);

                if (! $isInAvailableWindow || $overlapsUnavailable) {
                    throw ValidationException::withMessages([
                        'availability_type' => 'This change would make an existing published teaching schedule incompatible with faculty availability.',
                    ]);
                }
            }
        }
    }
}

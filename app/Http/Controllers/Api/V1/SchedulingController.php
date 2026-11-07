<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Scheduling\CancelScheduleAssignmentRequest;
use App\Http\Requests\Scheduling\IndexFacultyAssignmentsRequest;
use App\Http\Requests\Scheduling\IndexScheduleConflictsRequest;
use App\Http\Requests\Scheduling\IndexSchedulesRequest;
use App\Http\Requests\Scheduling\ReleaseRoomAllocationRequest;
use App\Http\Requests\Scheduling\ResolveScheduleConflictRequest;
use App\Http\Requests\Scheduling\SchedulingOptionsRequest;
use App\Http\Requests\Scheduling\StoreClassScheduleRequest;
use App\Http\Requests\Scheduling\StoreFacultyAssignmentRequest;
use App\Http\Requests\Scheduling\StoreRoomAllocationRequest;
use App\Http\Requests\Scheduling\StoreScheduleAssignmentRequest;
use App\Http\Requests\Scheduling\UpdateClassScheduleRequest;
use App\Http\Requests\Scheduling\UpdateFacultyAssignmentStatusRequest;
use App\Http\Requests\Scheduling\WaiveScheduleConflictRequest;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\FacultyAssignment;
use App\Models\Professor;
use App\Models\Room;
use App\Models\RoomAllocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleConflict;
use App\Models\Section;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Services\Scheduling\ScheduleManagementService;
use App\Support\ApiResponse;
use App\Support\SchedulePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SchedulingController extends Controller
{
    public function __construct(private readonly ScheduleManagementService $scheduling) {}

    public function options(SchedulingOptionsRequest $request): JsonResponse
    {
        $termId = $request->validated('academic_term_id');
        $offerings = SubjectOffering::query()
            ->where('academic_term_id', $termId)
            ->where('status', 'open')
            ->whereHas('section', fn (Builder $query) => $query->where('status', 'active'))
            ->whereHas('subject', fn (Builder $query) => $query->where('status', 'active'))
            ->with([
                'subject:id,code,title',
                'section:id,code,name,program_id,year_level_id,academic_term_id',
                'section.program:id,code,name',
                'section.yearLevel:id,level,name',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (SubjectOffering $offering): array => [
                'id' => $offering->id,
                'expected_students' => $offering->expected_students,
                'subject' => [
                    'id' => $offering->subject->id,
                    'code' => $offering->subject->code,
                    'title' => $offering->subject->title,
                ],
                'section' => [
                    'id' => $offering->section->id,
                    'code' => $offering->section->code,
                    'name' => $offering->section->name,
                    'program' => [
                        'id' => $offering->section->program->id,
                        'code' => $offering->section->program->code,
                        'name' => $offering->section->program->name,
                    ],
                    'year_level' => [
                        'id' => $offering->section->yearLevel->id,
                        'name' => $offering->section->yearLevel->name,
                    ],
                ],
            ])->all();

        return ApiResponse::success([
            'terms' => AcademicTerm::query()
                ->whereIn('status', ['active', 'planned'])
                ->orderByDesc('is_current')
                ->orderByDesc('starts_on')
                ->get(['id', 'academic_year', 'term', 'name', 'status', 'is_current']),
            'offerings' => $offerings,
            'programs' => Section::query()
                ->where('academic_term_id', $termId)
                ->with('program:id,code,name')
                ->get(['id', 'program_id', 'academic_term_id'])
                ->pluck('program')
                ->unique('id')
                ->values()
                ->map(fn ($program): array => [
                    'id' => $program->id,
                    'code' => $program->code,
                    'name' => $program->name,
                ]),
            'sections' => Section::query()
                ->where('academic_term_id', $termId)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'program_id', 'academic_term_id']),
            'subjects' => Subject::query()
                ->whereHas('subjectOfferings', fn (Builder $query) => $query->where('academic_term_id', $termId))
                ->orderBy('code')
                ->get(['id', 'code', 'title']),
            'professors' => Professor::query()
                ->with('user:id,name,status')
                ->where('status', 'active')
                ->whereHas('user', fn (Builder $query) => $query->where('status', 'active'))
                ->orderBy('id')
                ->get(['id', 'user_id', 'employee_number'])
                ->map(fn (Professor $professor): array => [
                    'id' => $professor->id,
                    'name' => $professor->user->name,
                    'employee_number' => $professor->employee_number,
                ])->all(),
            'rooms' => Room::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'building', 'capacity', 'room_type', 'status']),
        ], 'Scheduling options loaded.');
    }

    public function index(IndexSchedulesRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = ClassSchedule::query()
            ->with(self::scheduleRelations())
            ->whereHas('subjectOffering', fn (Builder $offering) => $offering
                ->where('academic_term_id', $filters['academic_term_id']));

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['section_id'])) {
            $query->whereHas('subjectOffering', fn (Builder $offering) => $offering
                ->where('section_id', $filters['section_id']));
        }
        if (! empty($filters['program_id'])) {
            $query->whereHas('subjectOffering.section', fn (Builder $section) => $section
                ->where('program_id', $filters['program_id']));
        }
        if (! empty($filters['subject_id'])) {
            $query->whereHas('subjectOffering', fn (Builder $offering) => $offering
                ->where('subject_id', $filters['subject_id']));
        }
        if (! empty($filters['professor_id'])) {
            $query->whereHas('scheduleAssignments', fn (Builder $assignment) => $assignment
                ->where('status', 'active')
                ->whereHas('facultyAssignment', fn (Builder $faculty) => $faculty
                    ->where('professor_id', $filters['professor_id'])
                    ->where('status', 'active')));
        }
        if (! empty($filters['room_id'])) {
            $query->whereHas('roomAllocations', fn (Builder $allocation) => $allocation
                ->where('room_id', $filters['room_id'])
                ->where('status', 'active'));
        }
        if (! empty($filters['day_of_week'])) {
            $query->where('day_of_week', $filters['day_of_week']);
        }
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->whereHas('subjectOffering', fn (Builder $offering) => $offering
                ->whereHas('subject', fn (Builder $subject) => $subject
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%'))
                ->orWhereHas('section', fn (Builder $section) => $section
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')));
        }

        $schedules = $query
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'schedules' => collect($schedules->items())->map(SchedulePresenter::toArray(...))->all(),
            'pagination' => self::pagination($schedules),
        ], 'Schedules loaded.');
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ClassSchedule::query()
            ->with(self::scheduleRelations())
            ->where('status', 'published')
            ->whereHas('subjectOffering.academicTerm', fn (Builder $term) => $term
                ->whereIn('status', ['active', 'planned'])
                ->whereDate('ends_on', '>=', now()->toDateString()));

        if ($user?->hasRole('professor') && $user->professor !== null && $user->isActive()) {
            if ($user->professor->status !== 'active') {
                throw new NotFoundHttpException('Schedule profile not found.');
            }
            $query->whereHas('scheduleAssignments', fn (Builder $assignment) => $assignment
                ->where('status', 'active')
                ->whereHas('facultyAssignment', fn (Builder $faculty) => $faculty
                    ->where('professor_id', $user->professor->id)
                    ->where('status', 'active')));
        } elseif ($user?->hasRole('student') && $user->student !== null && $user->isActive()) {
            if ($user->student->status !== 'active') {
                throw new NotFoundHttpException('Schedule profile not found.');
            }
            $offeringIds = $user->student->enrollments()
                ->where('status', 'enrolled')
                ->select('subject_offering_id');
            $query->whereIn('subject_offering_id', $offeringIds);
        } else {
            throw new NotFoundHttpException('Schedule profile not found.');
        }

        $schedules = $query->orderBy('day_of_week')->orderBy('start_time')->get();

        return ApiResponse::success([
            'schedules' => $schedules->map(fn (ClassSchedule $schedule): array => SchedulePresenter::toArray($schedule, false))->all(),
        ], 'Your schedules loaded.');
    }

    public function show(Request $request, ClassSchedule $schedule): JsonResponse
    {
        Gate::authorize('view', $schedule);
        $schedule->load(self::scheduleRelations());
        $includeHistory = $request->user()?->hasRole('administrator', 'department_personnel') ?? false;

        return ApiResponse::success(
            ['schedule' => SchedulePresenter::toArray($schedule, $includeHistory)],
            'Schedule loaded.',
        );
    }

    public function store(StoreClassScheduleRequest $request): JsonResponse
    {
        $schedule = $this->scheduling->createSchedule($request->validated());
        $schedule->load(self::scheduleRelations());

        return ApiResponse::success(
            ['schedule' => SchedulePresenter::toArray($schedule)],
            'Draft schedule created.',
            201,
        );
    }

    public function update(UpdateClassScheduleRequest $request, ClassSchedule $schedule): JsonResponse
    {
        $updated = $this->scheduling->updateDraftSchedule($schedule, $request->validated());
        $updated->load(self::scheduleRelations());

        return ApiResponse::success(['schedule' => SchedulePresenter::toArray($updated)], 'Draft schedule updated.');
    }

    public function preview(ClassSchedule $schedule): JsonResponse
    {
        Gate::authorize('update', $schedule);
        if ($schedule->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft schedules can be previewed.']);
        }

        return ApiResponse::success(['conflicts' => $this->scheduling->previewConflicts($schedule)], 'Conflict preview complete.');
    }

    public function publish(Request $request, ClassSchedule $schedule): JsonResponse
    {
        Gate::authorize('publish', $schedule);
        $result = $this->scheduling->publish($schedule);

        if ($result['conflicts'] !== []) {
            return ApiResponse::error(
                'The schedule cannot be published until its conflicts are addressed.',
                422,
                ['conflicts' => array_map(fn (array $conflict): string => $conflict['message'], $result['conflicts'])],
            );
        }

        $result['schedule']->load(self::scheduleRelations());

        return ApiResponse::success(
            ['schedule' => SchedulePresenter::toArray($result['schedule'])],
            'Schedule published.',
        );
    }

    public function cancel(Request $request, ClassSchedule $schedule): JsonResponse
    {
        Gate::authorize('cancel', $schedule);
        $cancelled = $this->scheduling->cancel($schedule, $request->user());
        $cancelled->load(self::scheduleRelations());

        return ApiResponse::success(['schedule' => SchedulePresenter::toArray($cancelled)], 'Schedule cancelled.');
    }

    public function facultyAssignments(IndexFacultyAssignmentsRequest $request): JsonResponse
    {
        $offeringId = $request->validated('subject_offering_id');
        $assignments = FacultyAssignment::query()
            ->where('subject_offering_id', $offeringId)
            ->with('professor.user:id,name,status')
            ->orderBy('assignment_role')
            ->orderBy('id')
            ->get()
            ->map(SchedulePresenter::facultyAssignmentArray(...))
            ->all();

        return ApiResponse::success(['assignments' => $assignments], 'Offering faculty assignments loaded.');
    }

    public function storeFacultyAssignment(StoreFacultyAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->scheduling->createFacultyAssignment($request->validated(), $request->user());
        $assignment->load('professor.user');

        return ApiResponse::success(
            ['assignment' => SchedulePresenter::facultyAssignmentArray($assignment)],
            'Faculty assignment created.',
            201,
        );
    }

    public function updateFacultyAssignmentStatus(
        UpdateFacultyAssignmentStatusRequest $request,
        FacultyAssignment $assignment,
    ): JsonResponse {
        $updated = $this->scheduling->updateFacultyAssignmentStatus($assignment, $request->validated('status'));
        $updated->load('professor.user');

        return ApiResponse::success(
            ['assignment' => SchedulePresenter::facultyAssignmentArray($updated)],
            'Faculty assignment status updated.',
        );
    }

    public function storeScheduleAssignment(
        StoreScheduleAssignmentRequest $request,
        ClassSchedule $schedule,
    ): JsonResponse {
        Gate::authorize('update', $schedule);
        $input = $request->validated();
        $assignment = $this->scheduling->assignFaculty(
            $schedule,
            $input['faculty_assignment_id'],
            (bool) ($input['is_primary'] ?? false),
        );
        $schedule->load(self::scheduleRelations());

        return ApiResponse::success([
            'assignment' => $assignment,
            'schedule' => SchedulePresenter::toArray($schedule),
        ], 'Instructor assigned to the draft schedule.', 201);
    }

    public function cancelScheduleAssignment(
        CancelScheduleAssignmentRequest $request,
        ClassSchedule $schedule,
        ScheduleAssignment $assignment,
    ): JsonResponse {
        $updated = $this->scheduling->cancelScheduleAssignment($schedule, $assignment);

        return ApiResponse::success(['assignment' => $updated], 'Instructor removed from the draft schedule.');
    }

    public function storeRoomAllocation(
        StoreRoomAllocationRequest $request,
        ClassSchedule $schedule,
    ): JsonResponse {
        Gate::authorize('update', $schedule);
        $allocation = $this->scheduling->allocateRoom($schedule, $request->validated('room_id'));
        $schedule->load(self::scheduleRelations());

        return ApiResponse::success([
            'allocation' => $allocation,
            'schedule' => SchedulePresenter::toArray($schedule),
        ], 'Room allocated to the draft schedule.', 201);
    }

    public function releaseRoomAllocation(
        ReleaseRoomAllocationRequest $request,
        ClassSchedule $schedule,
        RoomAllocation $allocation,
    ): JsonResponse {
        Gate::authorize('update', $schedule);
        $released = $this->scheduling->releaseRoom($schedule, $allocation);

        return ApiResponse::success(['allocation' => $released], 'Room released from the draft schedule.');
    }

    public function conflicts(IndexScheduleConflictsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = ScheduleConflict::query()
            ->with([
                'classSchedule.subjectOffering.subject',
                'classSchedule.subjectOffering.section',
                'conflictingClassSchedule.subjectOffering.subject',
                'conflictingClassSchedule.subjectOffering.section',
                'room:id,code,name',
                'professor:id,employee_number,user_id',
                'professor.user:id,name',
                'section:id,code,name',
            ]);

        if (! empty($filters['academic_term_id'])) {
            $query->where('academic_term_id', $filters['academic_term_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['conflict_type'])) {
            $query->where('conflict_type', $filters['conflict_type']);
        }

        $conflicts = $query->orderByDesc('detected_at')->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'conflicts' => $conflicts->getCollection()->map(fn (ScheduleConflict $conflict): array => [
                'id' => $conflict->id,
                'academic_term_id' => $conflict->academic_term_id,
                'conflict_type' => $conflict->conflict_type,
                'status' => $conflict->status,
                'detected_at' => $conflict->detected_at?->toIso8601String(),
                'resolution_notes' => $conflict->resolution_notes,
                'resource' => match ($conflict->conflict_type) {
                    'room' => $conflict->room?->code,
                    'faculty' => $conflict->professor?->user?->name,
                    default => $conflict->section?->code,
                },
                'schedules' => [
                    $this->conflictScheduleSummary($conflict->classSchedule),
                    $this->conflictScheduleSummary($conflict->conflictingClassSchedule),
                ],
            ])->all(),
            'pagination' => self::pagination($conflicts),
        ], 'Schedule conflicts loaded.');
    }

    public function resolveConflict(ResolveScheduleConflictRequest $request, ScheduleConflict $conflict): JsonResponse
    {
        $updated = $this->scheduling->resolveConflict(
            $conflict,
            $request->user(),
            $request->validated('resolution_notes'),
        );

        return ApiResponse::success(['conflict' => $updated], 'Schedule conflict resolved.');
    }

    public function waiveConflict(WaiveScheduleConflictRequest $request, ScheduleConflict $conflict): JsonResponse
    {
        $updated = $this->scheduling->waiveConflict(
            $conflict,
            $request->user(),
            $request->validated('resolution_notes'),
        );

        return ApiResponse::success(['conflict' => $updated], 'Schedule conflict waived.');
    }

    private function conflictScheduleSummary(ClassSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'day_of_week' => $schedule->day_of_week,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),
            'status' => $schedule->status,
            'subject_code' => $schedule->subjectOffering->subject->code,
            'subject_title' => $schedule->subjectOffering->subject->title,
            'section_code' => $schedule->subjectOffering->section->code,
        ];
    }

    private static function scheduleRelations(): array
    {
        return [
            'subjectOffering.subject:id,code,title',
            'subjectOffering.section:id,code,name,program_id,year_level_id,academic_term_id',
            'subjectOffering.section.program:id,code,name',
            'subjectOffering.section.yearLevel:id,level,name',
            'subjectOffering.academicTerm:id,academic_year,term,name,starts_on,ends_on',
            'scheduleAssignments.facultyAssignment.professor.user:id,name',
            'roomAllocations.room:id,code,name,building,capacity',
        ];
    }

    private static function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}

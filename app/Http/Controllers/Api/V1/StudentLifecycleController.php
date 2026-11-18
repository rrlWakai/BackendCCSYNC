<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentAcademicPlacement;
use App\Models\StudentActivityLog;
use App\Models\StudentRemoval;
use App\Services\Students\StudentLifecycleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class StudentLifecycleController extends Controller
{
    public function __construct(private readonly StudentLifecycleService $lifecycle) {}

    public function activityLogs(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);

        $logs = StudentActivityLog::query()
            ->with([
                'student:id,user_id,student_number,status',
                'student.user:id,name',
                'actor:id,name',
            ])
            ->latest('id')
            ->paginate($this->perPage($request));

        return ApiResponse::success([
            'logs' => $logs->getCollection()
                ->map(fn (StudentActivityLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'metadata' => $log->metadata,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'student' => [
                        'id' => $log->student->id,
                        'student_number' => $log->student->student_number,
                        'name' => $log->student->user?->name,
                        'status' => $log->student->status,
                    ],
                    'actor' => $log->actor === null ? null : [
                        'id' => $log->actor->id,
                        'name' => $log->actor->name,
                    ],
                ])
                ->all(),
            'pagination' => $this->pagination($logs),
        ], 'Student activity logs loaded.');
    }

    public function academicHistory(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);

        $placements = StudentAcademicPlacement::query()
            ->with([
                'student:id,user_id,student_number,status',
                'student.user:id,name',
                'student.removals' => fn ($query) => $query->pending(),
                'academicYear:id,label,status',
                'academicTerm:id,academic_year,term,name',
                'program:id,code,name',
                'yearLevel:id,level,name',
                'major:id,program_id,code,name',
                'section:id,code,name',
            ])
            ->orderByDesc('is_current')
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::success([
            'records' => $placements->getCollection()
                ->map(fn (StudentAcademicPlacement $placement): array => [
                    'id' => $placement->id,
                    'status' => $placement->status,
                    'is_current' => $placement->is_current,
                    'effective_on' => $placement->effective_on?->toDateString(),
                    'ends_on' => $placement->ends_on?->toDateString(),
                    'removal_pending' => $placement->student->removals->isNotEmpty(),
                    'student' => [
                        'id' => $placement->student->id,
                        'user_id' => $placement->student->user_id,
                        'student_number' => $placement->student->student_number,
                        'name' => $placement->student->user?->name,
                        'status' => $placement->student->status,
                    ],
                    'academic_year' => $placement->academicYear === null ? null : [
                        'id' => $placement->academicYear->id,
                        'label' => $placement->academicYear->label,
                    ],
                    'academic_term' => $placement->academicTerm === null ? null : [
                        'id' => $placement->academicTerm->id,
                        'academic_year' => $placement->academicTerm->academic_year,
                        'term' => $placement->academicTerm->term,
                        'name' => $placement->academicTerm->name,
                    ],
                    'program' => $placement->program === null ? null : [
                        'id' => $placement->program->id,
                        'code' => $placement->program->code,
                        'name' => $placement->program->name,
                    ],
                    'year_level' => $placement->yearLevel === null ? null : [
                        'id' => $placement->yearLevel->id,
                        'level' => $placement->yearLevel->level,
                        'name' => $placement->yearLevel->name,
                    ],
                    'major' => $placement->major === null ? null : [
                        'id' => $placement->major->id,
                        'code' => $placement->major->code,
                        'name' => $placement->major->name,
                    ],
                    'section' => $placement->section === null ? null : [
                        'id' => $placement->section->id,
                        'code' => $placement->section->code,
                        'name' => $placement->section->name,
                    ],
                ])
                ->all(),
            'pagination' => $this->pagination($placements),
        ], 'Student academic history loaded.');
    }

    public function removed(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Student::class);

        $now = now();
        $removals = StudentRemoval::query()
            ->pending()
            ->with([
                'student:id,user_id,student_number,status',
                'student.user:id,name',
                'removedBy:id,name',
            ])
            ->orderBy('restore_deadline')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return ApiResponse::success([
            'server_time' => $now->toIso8601String(),
            'removals' => $removals->getCollection()
                ->map(fn (StudentRemoval $removal): array => $this->removalArray($removal, $now))
                ->all(),
            'pagination' => $this->pagination($removals),
        ], 'Recently removed students loaded.');
    }

    public function remove(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('remove', $student);

        $removal = $this->lifecycle->remove($student, $request->user());

        return ApiResponse::success(
            ['removal' => $this->removalArray($removal)],
            'Student removed. The record can be restored for seven days.',
        );
    }

    public function restore(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('restore', $student);

        $removal = $this->lifecycle->restore($student, $request->user());

        return ApiResponse::success(
            ['removal' => $this->removalArray($removal)],
            'Student restored.',
        );
    }

    private function removalArray(StudentRemoval $removal, $now = null): array
    {
        $removal->loadMissing(['student.user', 'removedBy', 'restoredBy']);
        $now ??= now();
        $deadline = $removal->restore_deadline;
        $remaining = $deadline !== null && $now->lessThan($deadline)
            ? (int) $now->diffInSeconds($deadline)
            : 0;

        return [
            'id' => $removal->id,
            'status' => $removal->status,
            'removed_at' => $removal->removed_at?->toIso8601String(),
            'restore_deadline' => $deadline?->toIso8601String(),
            'remaining_seconds' => $remaining,
            'restored_at' => $removal->restored_at?->toIso8601String(),
            'student' => [
                'id' => $removal->student->id,
                'user_id' => $removal->student->user_id,
                'student_number' => $removal->student->student_number,
                'name' => $removal->student->user?->name,
                'status' => $removal->student->status,
            ],
            'removed_by' => $removal->removedBy === null ? null : [
                'id' => $removal->removedBy->id,
                'name' => $removal->removedBy->name,
            ],
            'restored_by' => $removal->restoredBy === null ? null : [
                'id' => $removal->restoredBy->id,
                'name' => $removal->restoredBy->name,
            ],
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 25)));
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}

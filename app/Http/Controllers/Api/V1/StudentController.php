<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\IndexStudentsRequest;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\StudentPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StudentController extends Controller
{
    public function index(IndexStudentsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = Student::query()
            ->select('students.*')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->with(['user:id,name,email,status,created_at', 'program:id,code,name,status'])
            ->whereHas('user.role', fn ($roleQuery) => $roleQuery->where('code', Role::STUDENT));

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('students.student_number', 'like', '%'.$search.'%')
                    ->orWhere('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['status'])) {
            $query->where('users.status', $filters['status']);
        }

        if (! empty($filters['program_id'])) {
            $query->where('students.program_id', $filters['program_id']);
        }

        $students = $query
            ->orderBy('users.name')
            ->orderBy('students.id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'students' => collect($students->items())->map(StudentPresenter::toDirectoryArray(...))->all(),
            'pagination' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ],
        ], 'Students loaded.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user?->student;

        if ($student === null) {
            throw new NotFoundHttpException('Student profile not found.');
        }

        Gate::authorize('view', $student);
        $student = $this->loadDetail($student);

        return ApiResponse::success(
            ['student' => StudentPresenter::toDetailArray($student, true)],
            'Student profile loaded.',
        );
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('view', $student);
        $actor = $request->user();
        $includeEmail = $actor?->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL, Role::STUDENT) ?? false;

        $student = $this->loadDetail($student, $actor);

        return ApiResponse::success(
            ['student' => StudentPresenter::toDetailArray($student, $includeEmail)],
            'Student profile loaded.',
        );
    }

    private function loadDetail(Student $student, ?User $actor = null): Student
    {
        $enrollmentQuery = fn (HasMany $query) => $query->with([
            'subjectOffering.subject:id,code,title,units',
            'subjectOffering.academicTerm:id,academic_year,term,name',
            'subjectOffering.section:id,program_id,year_level_id,academic_term_id,code,name',
            'subjectOffering.section.program:id,code,name',
            'subjectOffering.section.yearLevel:id,level,name',
        ]);

        if ($actor?->hasRole(Role::PROFESSOR)) {
            $professorId = $actor->professor?->id;
            $enrollmentQuery = fn (HasMany $query) => $query
                ->whereHas('subjectOffering.facultyAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('professor_id', $professorId)
                    ->where('status', 'active'))
                ->with([
                    'subjectOffering.subject:id,code,title,units',
                    'subjectOffering.academicTerm:id,academic_year,term,name',
                    'subjectOffering.section:id,program_id,year_level_id,academic_term_id,code,name',
                    'subjectOffering.section.program:id,code,name',
                    'subjectOffering.section.yearLevel:id,level,name',
                ]);
        }

        return $student->load([
            'user:id,name,email,status,created_at',
            'program:id,code,name,status',
            'enrollments' => $enrollmentQuery,
        ]);
    }
}

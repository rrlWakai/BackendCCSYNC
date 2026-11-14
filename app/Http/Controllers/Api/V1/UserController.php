<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\IndexUsersRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\StoreDepartmentRequest;
use App\Http\Requests\Users\UpdateDepartmentPersonnelProgramAccessRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdateUserStatusRequest;
use App\Models\Professor;
use App\Models\ProfessorRegistrationReview;
use App\Models\Department;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\UserAccountPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UserController extends Controller
{
    public function index(IndexUsersRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $actor = $request->user();
        $query = User::query()->with([
            'role',
            'student.program',
            'student.section.yearLevel',
            'professor.program',
            'professor.department',
            'departmentPersonnelPrograms',
        ]);

        if ($actor->hasRole(Role::DEPARTMENT_PERSONNEL)) {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('code', [
                Role::PROFESSOR,
                Role::STUDENT,
            ]));
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($userQuery) use ($search): void {
                $userQuery->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['role'])) {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', $filters['role']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $users = $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success([
            'users' => collect($users->items())
                ->map(fn (User $user): array => UserAccountPresenter::toArray($user))
                ->all(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            'overview' => $actor->hasRole(Role::ADMINISTRATOR) ? [
                'total_accounts' => User::query()->count(),
                'active_accounts' => User::query()->where('status', 'active')->count(),
                'pending_approvals' => User::query()->where('status', 'pending_approval')->count(),
                'deactivated_accounts' => User::query()->whereIn('status', ['inactive', 'suspended'])->count(),
            ] : null,
        ], 'Accounts loaded.');
    }

    public function options(IndexUsersRequest $request): JsonResponse
    {
        $actor = $request->user();
        $roles = $actor->hasRole(Role::ADMINISTRATOR)
            ? Role::query()->whereIn('code', [
                Role::ADMINISTRATOR,
                Role::DEPARTMENT_PERSONNEL,
                Role::PROFESSOR,
                Role::STUDENT,
            ])->orderBy('id')->get(['id', 'code', 'name'])
            : collect();

        return ApiResponse::success([
            'roles' => $roles,
            'programs' => Program::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'status']),
            'departments' => Department::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'status']),
        ], 'Account options loaded.');
    }

    public function storeDepartment(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return ApiResponse::success(
            ['department' => $department],
            'Department configured.',
            201,
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::success(
            ['user' => UserAccountPresenter::toArray(
                $user,
                $request->user()->hasRole(Role::ADMINISTRATOR),
            )],
            'Account loaded.',
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        $role = Role::query()->where('code', $attributes['role'])->firstOrFail();

        $user = DB::transaction(function () use ($attributes, $role): User {
            $user = User::create([
                'role_id' => $role->id,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => 'active',
            ]);

            if ($role->code === Role::STUDENT) {
                $user->student()->create([
                    ...$attributes['profile'],
                    'status' => 'active',
                ]);
            } elseif ($role->code === Role::PROFESSOR) {
                $user->professor()->create([
                    ...$attributes['profile'],
                    'status' => 'active',
                ]);
            }

            return $user;
        });

        return ApiResponse::success(
            ['user' => UserAccountPresenter::toArray($user)],
            'Account created.',
            201,
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $attributes = $request->validated();

        DB::transaction(function () use ($attributes, $user): void {
            $user->fill(collect($attributes)->only(['name', 'email'])->all());

            if (isset($attributes['password']) && $attributes['password'] !== '') {
                $user->password = $attributes['password'];
            }

            $user->save();

            if (! isset($attributes['profile'])) {
                return;
            }

            if ($user->hasRole(Role::STUDENT)) {
                $user->student()->updateOrCreate([], $attributes['profile']);
            } elseif ($user->hasRole(Role::PROFESSOR)) {
                $user->professor()->updateOrCreate([], $attributes['profile']);
            }
        });

        return ApiResponse::success(
            ['user' => UserAccountPresenter::toArray($user->fresh())],
            'Account updated.',
        );
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $status = $request->validated('status');

        DB::transaction(function () use ($request, $status, $user): void {
            if ($user->hasRole(Role::PROFESSOR)
                && in_array($user->status, ['pending_approval', 'needs_correction', 'rejected'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Professor registrations must be approved or rejected through the review workflow.',
                ]);
            }

            if ($user->hasRole(Role::ADMINISTRATOR) && $status === 'inactive') {
                if ($user->is($request->user())) {
                    throw ValidationException::withMessages([
                        'status' => 'You cannot deactivate your own Administrator account.',
                    ]);
                }

                $activeAdministrators = User::query()
                    ->where('status', 'active')
                    ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', Role::ADMINISTRATOR))
                    ->lockForUpdate()
                    ->get(['users.id'])
                    ->count();

                if ($activeAdministrators <= 1) {
                    throw ValidationException::withMessages([
                        'status' => 'The last active Administrator account cannot be deactivated.',
                    ]);
                }
            }

            if ($user->hasRole(Role::PROFESSOR) && $status === 'inactive') {
                $professor = Professor::query()->where('user_id', $user->id)->lockForUpdate()->first();
                $hasPublishedSchedules = $professor?->facultyAssignments()
                    ->where('status', 'active')
                    ->whereHas('scheduleAssignments', fn ($assignmentQuery) => $assignmentQuery
                        ->where('status', 'active')
                        ->whereHas('classSchedule', fn ($scheduleQuery) => $scheduleQuery
                            ->where('status', 'published')))
                    ->exists() ?? false;

                if ($hasPublishedSchedules) {
                    throw ValidationException::withMessages([
                        'status' => 'Cancel or reassign published schedules before deactivating this Professor account.',
                    ]);
                }
            }

            $user->update(['status' => $status]);
        });

        return ApiResponse::success(
            ['user' => UserAccountPresenter::toArray($user->fresh())],
            $status === 'active' ? 'Account activated.' : 'Account deactivated.',
        );
    }

    public function approveProfessor(Request $request, User $user): JsonResponse
    {
        return $this->reviewProfessorRegistration($request, $user, 'approved');
    }

    public function returnProfessorForCorrection(Request $request, User $user): JsonResponse
    {
        return $this->reviewProfessorRegistration($request, $user, 'returned_for_correction');
    }

    public function rejectProfessor(Request $request, User $user): JsonResponse
    {
        return $this->reviewProfessorRegistration($request, $user, 'rejected');
    }

    private function reviewProfessorRegistration(Request $request, User $user, string $action): JsonResponse
    {
        Gate::authorize('reviewProfessorRegistration', $user);

        $reasonRequired = in_array($action, ['returned_for_correction', 'rejected'], true);
        $attributes = $request->validate([
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($attributes, $request, $user, $action): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $lockedUser->hasRole(Role::PROFESSOR)
                || $lockedUser->status !== 'pending_approval') {
                throw ValidationException::withMessages([
                    'registration' => 'Only pending professor registrations can be reviewed.',
                ]);
            }

            $professor = Professor::query()
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->firstOrFail();
            $departmentIsActive = $professor->department_id !== null
                && Department::query()
                    ->whereKey($professor->department_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->exists();

            if ($action === 'approved'
                && (trim((string) $professor->employee_number) === '' || ! $departmentIsActive)) {
                throw ValidationException::withMessages([
                    'registration' => 'Assign an employee number and department before approving this account.',
                ]);
            }

            $lockedUser->update([
                'status' => match ($action) {
                    'approved' => 'active',
                    'returned_for_correction' => 'needs_correction',
                    'rejected' => 'rejected',
                },
            ]);

            ProfessorRegistrationReview::create([
                'account_user_id' => $lockedUser->id,
                'actor_user_id' => $request->user()->id,
                'action' => $action,
                'reason' => $attributes['reason'] ?? null,
                'registration_snapshot' => [
                    'name' => $lockedUser->name,
                    'email' => $lockedUser->email,
                    'employee_number' => $professor->employee_number,
                    'employment_type' => $professor->employment_type,
                    'department_id' => $professor->department_id,
                    'professor_id' => $professor->id,
                ],
            ]);
        });

        $message = match ($action) {
            'approved' => 'Professor registration approved.',
            'returned_for_correction' => 'Professor registration returned for correction.',
            'rejected' => 'Professor registration rejected.',
        };

        return ApiResponse::success(
            ['user' => UserAccountPresenter::toArray(
                $user->fresh(),
                true,
            )],
            $message,
        );
    }

    public function updateDepartmentPersonnelProgramAccess(
        UpdateDepartmentPersonnelProgramAccessRequest $request,
        User $user,
    ): JsonResponse {
        $programIds = $request->validated('program_ids');

        DB::transaction(function () use ($user, $programIds): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->departmentPersonnelPrograms()->sync($programIds);
        });

        return ApiResponse::success(
            ['user' => UserAccountPresenter::toArray($user->fresh())],
            'Department Personnel program access updated.',
        );
    }
}

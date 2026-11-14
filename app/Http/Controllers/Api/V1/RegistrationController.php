<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterProfessorRequest;
use App\Http\Requests\Auth\RegisterStudentRequest;
use App\Models\Department;
use App\Models\AcademicTerm;
use App\Models\ProfessorRegistrationReview;
use App\Models\Program;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Models\YearLevel;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

final class RegistrationController extends Controller
{
    public function registerStudent(RegisterStudentRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        DB::transaction(function () use ($attributes): void {
            $program = Program::query()
                ->whereKey($attributes['program_id'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            $section = Section::query()
                ->whereKey($attributes['section_id'])
                ->where('status', 'active')
                ->where('program_id', $attributes['program_id'])
                ->where('year_level_id', $attributes['year_level_id'])
                ->lockForUpdate()
                ->first();
            $yearLevelExists = YearLevel::query()->whereKey($attributes['year_level_id'])->exists();
            $academicTerm = $section !== null ? AcademicTerm::query()
                ->whereKey($section->academic_term_id)
                ->where('status', 'active')
                ->where('is_current', true)
                ->lockForUpdate()
                ->first() : null;

            if ($program === null) {
                throw ValidationException::withMessages([
                    'program_id' => 'Choose an active academic program.',
                ]);
            }

            if ($section === null || ! $yearLevelExists || $academicTerm === null) {
                throw ValidationException::withMessages([
                    'section_id' => 'Choose an active section for the selected program and year level.',
                ]);
            }

            $role = Role::query()->where('code', Role::STUDENT)->firstOrFail();
            $user = User::create([
                'role_id' => $role->id,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => 'active',
            ]);

            $user->student()->create([
                'student_number' => $attributes['student_number'],
                'program_id' => $program->id,
                'section_id' => $section->id,
                'status' => 'active',
            ]);

        });

        return ApiResponse::success(null, 'Student account registered. You can now sign in.', 201);
    }

    public function registerProfessor(RegisterProfessorRequest $request): JsonResponse
    {
        $attributes = $request->validated();
        DB::transaction(function () use ($attributes): void {
            $department = Department::query()
                ->whereKey($attributes['department_id'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($department === null
                || ($attributes['department_affiliation'] === 'ccs' && $department->code !== 'CCS')
                || ($attributes['department_affiliation'] === 'other' && $department->code === 'CCS')) {
                throw ValidationException::withMessages([
                    'department_id' => 'Choose a department matching the selected affiliation.',
                ]);
            }

            $role = Role::query()->where('code', Role::PROFESSOR)->firstOrFail();
            $user = User::create([
                'role_id' => $role->id,
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'status' => 'pending_approval',
            ]);

            $professor = $user->professor()->create([
                'department_id' => $department->id,
                'employment_type' => $attributes['employment_type'],
                'status' => 'active',
            ]);

            $this->recordSubmission($user, $attributes, $professor->id, 'submitted');
        });

        return ApiResponse::success(null, 'Registration submitted. Your professor account is pending administrator approval.', 201);
    }

    public function resubmitProfessor(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }

        $attributes = $request->validate([
            'current_email' => ['required', 'string', 'email', 'max:255', new \App\Rules\InstitutionalEmail],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new \App\Rules\InstitutionalEmail],
            'password' => ['required', 'string'],
            'password_confirmation' => ['required', 'same:password'],
            'department_affiliation' => ['required', Rule::in(['ccs', 'other'])],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('status', 'active')],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time'])],
        ]);

        $user = User::query()->where('email', $attributes['current_email'])->whereHas(
            'role',
            fn ($query) => $query->where('code', Role::PROFESSOR),
        )->first();

        if ($user === null
            || $user->status !== 'needs_correction'
            || ! Hash::check($attributes['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The registration could not be verified for correction.',
            ]);
        }

        if (User::query()->where('email', $attributes['email'])->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email address is already in use.',
            ]);
        }

        DB::transaction(function () use ($attributes, $user): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->status !== 'needs_correction') {
                throw ValidationException::withMessages([
                    'email' => 'The registration is no longer awaiting correction.',
                ]);
            }

            $department = Department::query()
                ->whereKey($attributes['department_id'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($department === null
                || ($attributes['department_affiliation'] === 'ccs' && $department->code !== 'CCS')
                || ($attributes['department_affiliation'] === 'other' && $department->code === 'CCS')) {
                throw ValidationException::withMessages([
                    'department_id' => 'Choose a department matching the selected affiliation.',
                ]);
            }

            $lockedUser->update([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'status' => 'pending_approval',
            ]);
            $lockedUser->professor()->update([
                'department_id' => $department->id,
                'employment_type' => $attributes['employment_type'],
            ]);

            $this->recordSubmission($lockedUser, $attributes, $lockedUser->professor->id, 'resubmitted');
        });

        return ApiResponse::success(null, 'Registration resubmitted for administrator review.');
    }

    public function professorCorrection(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }

        $attributes = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', new \App\Rules\InstitutionalEmail],
            'password' => ['required', 'string'],
        ]);
        $user = User::query()
            ->where('email', $attributes['email'])
            ->where('status', 'needs_correction')
            ->whereHas('role', fn ($query) => $query->where('code', Role::PROFESSOR))
            ->with(['professor.department'])
            ->first();

        if ($user === null || ! Hash::check($attributes['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The returned registration could not be verified.',
            ]);
        }

        $latestReview = ProfessorRegistrationReview::query()
            ->where('account_user_id', $user->id)
            ->where('action', 'returned_for_correction')
            ->latest('id')
            ->firstOrFail();

        return ApiResponse::success([
            'registration' => [
                'name' => $user->name,
                'email' => $user->email,
                'employment_type' => $user->professor->employment_type,
                'department_id' => $user->professor->department_id,
                'department_affiliation' => $user->professor->department?->code === 'CCS' ? 'ccs' : 'other',
                'correction_reason' => $latestReview->reason,
            ],
        ], 'Returned registration loaded.');
    }

    private function recordSubmission(User $user, array $attributes, int $professorId, string $action): void
    {
        ProfessorRegistrationReview::create([
            'account_user_id' => $user->id,
            'actor_user_id' => $user->id,
            'action' => $action,
            'registration_snapshot' => [
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'department_id' => $attributes['department_id'],
                'employment_type' => $attributes['employment_type'],
                'professor_id' => $professorId,
            ],
        ]);
    }
}

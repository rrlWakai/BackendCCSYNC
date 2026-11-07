<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

final class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('update', $target) ?? false);
    }

    public function rules(): array
    {
        $target = $this->route('user');
        $isStudent = $target instanceof User && $target->hasRole(Role::STUDENT);
        $isProfessor = $target instanceof User && $target->hasRole(Role::PROFESSOR);
        $profileKeys = $isStudent
            ? 'student_number,program_id,admitted_on'
            : 'employee_number,program_id,employment_type,specialization';
        $studentNumberRule = Rule::unique('students', 'student_number');
        $employeeNumberRule = Rule::unique('professors', 'employee_number');
        $existingProgramId = $isStudent
            ? $target->student?->program_id
            : ($isProfessor ? $target->professor?->program_id : null);
        $programRule = Rule::exists('programs', 'id')->where(
            fn ($query) => $query->where('status', 'active')
                ->when($existingProgramId !== null, fn ($query) => $query->orWhere('id', $existingProgramId)),
        );

        if ($target instanceof User && $target->student !== null) {
            $studentNumberRule->ignore($target->student->id);
        }

        if ($target instanceof User && $target->professor !== null) {
            $employeeNumberRule->ignore($target->professor->id);
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target),
            ],
            'password' => ['sometimes', 'nullable', 'string', Password::min(12), 'confirmed'],
            'role' => ['prohibited'],
            'profile' => [
                'sometimes',
                Rule::prohibitedIf(! $isStudent && ! $isProfessor),
                'array:'.$profileKeys,
            ],
            'profile.student_number' => [
                Rule::requiredIf($isStudent && $this->has('profile')),
                Rule::prohibitedIf(! $isStudent),
                'string',
                'max:32',
                $studentNumberRule,
            ],
            'profile.employee_number' => [
                Rule::requiredIf($isProfessor && $this->has('profile')),
                Rule::prohibitedIf(! $isProfessor),
                'string',
                'max:32',
                $employeeNumberRule,
            ],
            'profile.program_id' => [
                Rule::requiredIf($isStudent && $this->has('profile')),
                Rule::prohibitedIf(! $isStudent && ! $isProfessor),
                'nullable',
                'integer',
                $programRule,
            ],
            'profile.admitted_on' => [
                Rule::prohibitedIf(! $isStudent),
                'nullable',
                'date',
            ],
            'profile.employment_type' => [
                Rule::requiredIf($isProfessor && $this->has('profile')),
                Rule::prohibitedIf(! $isProfessor),
                Rule::in(['full_time', 'part_time', 'visiting']),
            ],
            'profile.specialization' => [
                Rule::prohibitedIf(! $isProfessor),
                'nullable',
                'string',
                'max:160',
            ],
        ];
    }
}

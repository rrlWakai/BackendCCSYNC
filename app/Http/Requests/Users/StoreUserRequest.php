<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use App\Models\User;
use App\Rules\InstitutionalEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

final class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        $role = $this->input('role');
        $isStudent = $role === Role::STUDENT;
        $isProfessor = $role === Role::PROFESSOR;
        $profileKeys = $isStudent
            ? 'student_number,program_id,admitted_on'
            : 'employee_number,program_id,employment_type,specialization';
        $emailRules = ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')];

        if ($isStudent || $isProfessor) {
            $emailRules[] = new InstitutionalEmail;
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $emailRules,
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
            'role' => ['required', Rule::in([
                Role::ADMINISTRATOR,
                Role::DEPARTMENT_PERSONNEL,
                Role::PROFESSOR,
                Role::STUDENT,
            ])],
            'profile' => [
                Rule::requiredIf($isStudent || $isProfessor),
                Rule::prohibitedIf(! $isStudent && ! $isProfessor),
                'array:'.$profileKeys,
            ],
            'profile.student_number' => [
                Rule::requiredIf($isStudent),
                Rule::prohibitedIf(! $isStudent),
                'string',
                'max:32',
                Rule::unique('students', 'student_number'),
            ],
            'profile.employee_number' => [
                Rule::requiredIf($isProfessor),
                Rule::prohibitedIf(! $isProfessor),
                'string',
                'max:32',
                Rule::unique('professors', 'employee_number'),
            ],
            'profile.program_id' => [
                Rule::requiredIf($isStudent),
                Rule::prohibitedIf(! $isStudent && ! $isProfessor),
                'nullable',
                'integer',
                Rule::exists('programs', 'id')->where('status', 'active'),
            ],
            'profile.admitted_on' => [
                Rule::prohibitedIf(! $isStudent),
                'nullable',
                'date',
            ],
            'profile.employment_type' => [
                Rule::requiredIf($isProfessor),
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

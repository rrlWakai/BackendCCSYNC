<?php

namespace App\Http\Requests\Auth;

use App\Rules\InstitutionalEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

final class RegisterProfessorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new InstitutionalEmail, Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time'])],
            'department_affiliation' => ['required', Rule::in(['ccs', 'other'])],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('status', 'active')],
        ];
    }
}

<?php

namespace App\Http\Requests\Auth;

use App\Rules\InstitutionalEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

final class RegisterStudentRequest extends FormRequest
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
            'student_number' => ['required', 'string', 'max:32', Rule::unique('students', 'student_number')],
            'email' => ['required', 'string', 'email', 'max:255', new InstitutionalEmail, Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(12), 'confirmed'],
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')->where('status', 'active')],
            'year_level_id' => ['required', 'integer', Rule::exists('year_levels', 'id')],
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
        ];
    }
}

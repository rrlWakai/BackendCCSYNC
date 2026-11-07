<?php

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFacultyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator', 'department_personnel') ?? false;
    }

    public function rules(): array
    {
        return [
            'professor_id' => ['required', 'integer', 'exists:professors,id'],
            'subject_offering_id' => ['required', 'integer', 'exists:subject_offerings,id'],
            'assignment_role' => ['required', Rule::in(['lead', 'co_instructor', 'lab_instructor', 'substitute'])],
            'assigned_units' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.9'],
        ];
    }
}

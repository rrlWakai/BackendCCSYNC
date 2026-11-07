<?php

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

final class StoreScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator', 'department_personnel') ?? false;
    }

    public function rules(): array
    {
        return [
            'faculty_assignment_id' => ['required', 'integer', 'exists:faculty_assignments,id'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Scheduling;

use App\Http\Requests\ApiFormRequest;

final class IndexStudentScheduleConflictsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator', 'department_personnel') ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_terms,id'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'exists:programs,id'],
            'year_level_id' => ['sometimes', 'nullable', 'integer', 'exists:year_levels,id'],
            'section_id' => ['sometimes', 'nullable', 'integer', 'exists:sections,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

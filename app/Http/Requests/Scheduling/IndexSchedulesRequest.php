<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['draft', 'published', 'cancelled'])],
            'section_id' => ['sometimes', 'nullable', 'integer', 'exists:sections,id'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'exists:programs,id'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'exists:subjects,id'],
            'professor_id' => ['sometimes', 'nullable', 'integer', 'exists:professors,id'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'day_of_week' => ['sometimes', 'nullable', 'integer', 'between:1,7'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

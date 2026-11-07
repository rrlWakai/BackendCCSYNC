<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ScheduleConflict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexScheduleConflictsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ScheduleConflict::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_terms,id'],
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'resolved', 'waived'])],
            'conflict_type' => ['sometimes', 'nullable', Rule::in(['room', 'faculty', 'section'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

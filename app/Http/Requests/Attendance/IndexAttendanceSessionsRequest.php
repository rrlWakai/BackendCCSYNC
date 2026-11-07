<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\ApiFormRequest;
use App\Models\AttendanceSession;
use Illuminate\Validation\Rule;

final class IndexAttendanceSessionsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AttendanceSession::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'academic_term_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_terms,id'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'exists:programs,id'],
            'section_id' => ['sometimes', 'nullable', 'integer', 'exists:sections,id'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'exists:subjects,id'],
            'professor_id' => ['sometimes', 'nullable', 'integer', 'exists:professors,id'],
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'submitted', 'cancelled'])],
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

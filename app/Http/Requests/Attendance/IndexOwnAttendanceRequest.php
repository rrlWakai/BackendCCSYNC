<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\ApiFormRequest;
use App\Models\StudentAttendance;
use Illuminate\Validation\Rule;

final class IndexOwnAttendanceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewOwn', StudentAttendance::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_terms,id'],
            'subject_offering_id' => ['sometimes', 'nullable', 'integer', 'exists:subject_offerings,id'],
            'status' => ['sometimes', 'nullable', Rule::in(['present', 'absent', 'late', 'excused'])],
            'from_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

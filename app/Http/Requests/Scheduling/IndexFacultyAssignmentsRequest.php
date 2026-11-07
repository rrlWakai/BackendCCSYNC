<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;

final class IndexFacultyAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'subject_offering_id' => ['required', 'integer', 'exists:subject_offerings,id'],
        ];
    }
}

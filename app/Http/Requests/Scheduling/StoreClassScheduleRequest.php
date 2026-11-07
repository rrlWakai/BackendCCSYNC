<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClassScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'subject_offering_id' => ['required', 'integer', 'exists:subject_offerings,id'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'schedule_type' => ['required', Rule::in(['lecture', 'laboratory', 'seminar', 'other'])],
            'modality' => ['required', Rule::in(['face_to_face', 'online', 'hybrid'])],
        ];
    }
}

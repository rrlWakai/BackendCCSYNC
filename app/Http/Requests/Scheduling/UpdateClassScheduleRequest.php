<?php

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateClassScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('schedule')) ?? false;
    }

    public function rules(): array
    {
        return [
            'day_of_week' => ['sometimes', 'required', 'integer', 'between:1,7'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
            'schedule_type' => ['sometimes', 'required', Rule::in(['lecture', 'laboratory', 'seminar', 'other'])],
            'modality' => ['sometimes', 'required', Rule::in(['face_to_face', 'online', 'hybrid'])],
        ];
    }
}

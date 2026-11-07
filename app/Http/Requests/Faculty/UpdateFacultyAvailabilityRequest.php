<?php

namespace App\Http\Requests\Faculty;

use App\Models\FacultyAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacultyAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $availability = $this->route('availability');

        return $availability instanceof FacultyAvailability
            && ($this->user()?->can('manage', $availability) ?? false);
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'availability_type' => ['required', Rule::in(['available', 'unavailable', 'preferred'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

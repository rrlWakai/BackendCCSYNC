<?php

namespace App\Http\Requests\Faculty;

use App\Models\Professor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFacultyAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $professor = $this->route('professor');

        return $professor instanceof Professor
            && ($this->user()?->can('manageAvailability', $professor) ?? false);
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

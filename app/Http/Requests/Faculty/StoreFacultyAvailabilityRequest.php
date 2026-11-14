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
            'windows' => ['sometimes', 'array', 'list', 'min:1'],
            'windows.*' => ['required', 'array:day_of_week,start_time,end_time,availability_type,notes'],
            'windows.*.day_of_week' => ['required_with:windows', 'integer', 'between:1,7'],
            'windows.*.start_time' => ['required_with:windows', 'date_format:H:i'],
            'windows.*.end_time' => ['required_with:windows', 'date_format:H:i', 'after:windows.*.start_time'],
            'windows.*.availability_type' => ['required_with:windows', Rule::in(['available', 'unavailable', 'preferred'])],
            'windows.*.notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'day_of_week' => ['required_without:windows', 'prohibited_with:windows', 'integer', 'between:1,7'],
            'start_time' => ['required_without:windows', 'prohibited_with:windows', 'date_format:H:i'],
            'end_time' => ['required_without:windows', 'prohibited_with:windows', 'date_format:H:i', 'after:start_time'],
            'availability_type' => ['required_without:windows', 'prohibited_with:windows', Rule::in(['available', 'unavailable', 'preferred'])],
            'notes' => ['sometimes', 'nullable', 'prohibited_with:windows', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SolveSchedulingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => [
                'required',
                'integer',
                Rule::exists('academic_terms', 'id'),
            ],
            'correlation_id' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            ],
        ];
    }
}

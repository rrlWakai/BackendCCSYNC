<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

final class IndexAcademicRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in([
                'active',
                'inactive',
                'planned',
                'completed',
                'archived',
            ])],
            'program_id' => ['sometimes', 'integer', 'min:1'],
            'year_level_id' => ['sometimes', 'integer', 'min:1'],
            'academic_term_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

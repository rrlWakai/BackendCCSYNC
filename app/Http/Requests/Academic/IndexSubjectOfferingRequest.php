<?php

namespace App\Http\Requests\Academic;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

final class IndexSubjectOfferingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in([
                'draft',
                'open',
                'closed',
                'cancelled',
            ])],
            'academic_term_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'section_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

<?php

namespace App\Http\Requests\Academic;

use App\Models\SubjectOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSubjectOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SubjectOffering::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'expected_students' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}

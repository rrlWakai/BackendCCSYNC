<?php

namespace App\Http\Requests\Academic;

use App\Models\SubjectOffering;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSubjectOfferingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subjectOffering = $this->route('subjectOffering');

        return $subjectOffering instanceof SubjectOffering
            && ($this->user()?->can('update', $subjectOffering) ?? false);
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['sometimes', 'required', 'integer', Rule::exists('academic_terms', 'id')],
            'section_id' => ['sometimes', 'required', 'integer', Rule::exists('sections', 'id')],
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')],
            'expected_students' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}

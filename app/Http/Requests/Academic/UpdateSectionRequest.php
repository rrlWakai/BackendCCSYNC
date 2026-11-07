<?php

namespace App\Http\Requests\Academic;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateSectionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('section')) ?? false;
    }

    public function rules(): array
    {
        /** @var Section $section */
        $section = $this->route('section');

        return [
            'program_id' => ['sometimes', 'required', 'integer', Rule::exists('programs', 'id')],
            'year_level_id' => ['sometimes', 'required', 'integer', Rule::exists('year_levels', 'id')],
            'academic_term_id' => ['sometimes', 'required', 'integer', Rule::exists('academic_terms', 'id')],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('sections', 'code')
                    ->where(fn ($query) => $query->where('academic_term_id', $this->input('academic_term_id', $section->academic_term_id)))
                    ->ignore($section),
            ],
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
        ];
    }
}

<?php

namespace App\Http\Requests\Academic;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreSectionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Section::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'program_id' => ['required', 'integer', Rule::exists('programs', 'id')],
            'year_level_id' => ['required', 'integer', Rule::exists('year_levels', 'id')],
            'academic_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('sections', 'code')->where(fn ($query) => $query->where('academic_term_id', $this->input('academic_term_id'))),
            ],
            'name' => ['nullable', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'between:1,65535'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
        ];
    }
}

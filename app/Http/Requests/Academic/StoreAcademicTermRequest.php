<?php

namespace App\Http\Requests\Academic;

use App\Models\AcademicTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAcademicTermRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->boolean('is_current')) {
            $this->merge(['status' => $this->input('status', 'active')]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicTerm::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'academic_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/', Rule::unique('academic_terms')->where(fn ($query) => $query->where('term', $this->input('term')))],
            'term' => ['required', Rule::in(['first', 'second', 'summer'])],
            'name' => ['required', 'string', 'max:80'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'is_current' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'completed', 'archived'])],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                if (preg_match('/^(\d{4})-(\d{4})$/', (string) $this->input('academic_year'), $matches) === 1
                    && (int) $matches[2] !== (int) $matches[1] + 1) {
                    $validator->errors()->add('academic_year', 'The second year must immediately follow the first year.');
                }

                if ($this->boolean('is_current') && $this->input('status', 'planned') !== 'active') {
                    $validator->errors()->add('is_current', 'Only an active academic term can be current.');
                }
            },
        ];
    }
}

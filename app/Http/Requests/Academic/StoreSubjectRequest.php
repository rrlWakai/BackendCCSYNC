<?php

namespace App\Http\Requests\Academic;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreSubjectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Subject::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('subjects', 'code')],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'units' => ['required', 'numeric', 'gt:0', 'max:999.9'],
            'lecture_hours' => ['sometimes', 'numeric', 'gte:0', 'max:999.9'],
            'lab_hours' => ['sometimes', 'numeric', 'gte:0', 'max:999.9'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}

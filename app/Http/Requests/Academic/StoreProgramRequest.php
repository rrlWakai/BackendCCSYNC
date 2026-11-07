<?php

namespace App\Http\Requests\Academic;

use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreProgramRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => is_string($this->input('code')) ? Str::upper(trim($this->input('code'))) : $this->input('code'),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Program::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', Rule::unique('programs', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}

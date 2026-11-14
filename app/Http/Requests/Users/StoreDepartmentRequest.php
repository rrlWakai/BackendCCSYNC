<?php

namespace App\Http\Requests\Users;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreDepartmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::ADMINISTRATOR) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('departments', 'code')],
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}

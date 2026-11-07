<?php

namespace App\Http\Requests\Academic;

use App\Models\YearLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreYearLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', YearLevel::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'level' => ['required', 'integer', 'between:1,6', Rule::unique('year_levels', 'level')],
            'name' => ['required', 'string', 'max:32'],
        ];
    }
}

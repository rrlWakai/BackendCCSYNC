<?php

namespace App\Http\Requests\Academic;

use App\Models\YearLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateYearLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('year_level')) ?? false;
    }

    public function rules(): array
    {
        /** @var YearLevel $yearLevel */
        $yearLevel = $this->route('year_level');

        return [
            'level' => ['sometimes', 'required', 'integer', 'between:1,6', Rule::unique('year_levels', 'level')->ignore($yearLevel)],
            'name' => ['sometimes', 'required', 'string', 'max:32'],
        ];
    }
}

<?php

namespace App\Http\Requests\Activities;

use App\Http\Requests\ApiFormRequest;

final class IndexActivityOptionsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', \App\Models\Activity::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}

<?php

namespace App\Http\Requests\Activities;

use App\Http\Requests\ApiFormRequest;

final class IndexActivityHistoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

<?php

namespace App\Http\Requests\Activities;

use App\Http\Requests\ApiFormRequest;

final class CancelActivityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Rooms;

use App\Http\Requests\ApiFormRequest;

final class RevokeRoomQrRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}

<?php

namespace App\Http\Requests\Rooms;

use App\Http\Requests\ApiFormRequest;

final class ScanRoomRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'qr_identifier' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'mismatch_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}

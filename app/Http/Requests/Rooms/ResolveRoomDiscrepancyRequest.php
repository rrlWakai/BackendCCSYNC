<?php

namespace App\Http\Requests\Rooms;

use App\Http\Requests\ApiFormRequest;

final class ResolveRoomDiscrepancyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'resolution_notes' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}

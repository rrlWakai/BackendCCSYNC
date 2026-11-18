<?php

namespace App\Http\Requests\Rooms;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

final class RoomHistoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'building' => ['sometimes', 'nullable', 'string', 'max:100'],
            'discrepancy_status' => ['sometimes', 'nullable', Rule::in(['none', 'open', 'resolved'])],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

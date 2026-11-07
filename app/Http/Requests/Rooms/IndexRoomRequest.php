<?php

namespace App\Http\Requests\Rooms;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

final class IndexRoomRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in([
                'available',
                'unavailable',
                'maintenance',
                'retired',
            ])],
            'room_type' => ['sometimes', 'nullable', Rule::in([
                'lecture',
                'laboratory',
                'seminar',
                'office',
                'other',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

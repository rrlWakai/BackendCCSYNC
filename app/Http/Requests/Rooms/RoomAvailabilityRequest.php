<?php

namespace App\Http\Requests\Rooms;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

final class RoomAvailabilityRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'building' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'room_type' => ['sometimes', 'nullable', Rule::in([
                'lecture',
                'laboratory',
                'seminar',
                'office',
                'other',
            ])],
            'room_status' => ['sometimes', 'nullable', Rule::in([
                'available',
                'unavailable',
                'maintenance',
                'retired',
            ])],
            'availability_status' => ['sometimes', 'nullable', Rule::in([
                'available',
                'reserved',
                'occupied',
                'discrepancy',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ];
    }
}

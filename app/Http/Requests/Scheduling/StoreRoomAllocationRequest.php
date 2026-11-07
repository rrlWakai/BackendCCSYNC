<?php

namespace App\Http\Requests\Scheduling;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRoomAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('administrator', 'department_personnel') ?? false;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ];
    }
}

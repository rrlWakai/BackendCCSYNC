<?php

namespace App\Http\Requests\Rooms;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateRoomStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            && ($this->user()?->can('changeStatus', $room) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                'available',
                'unavailable',
                'maintenance',
                'retired',
            ])],
        ];
    }
}

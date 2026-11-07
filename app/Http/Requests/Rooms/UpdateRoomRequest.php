<?php

namespace App\Http\Requests\Rooms;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateRoomRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        foreach (['building', 'floor'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function authorize(): bool
    {
        $room = $this->route('room');

        return $room instanceof Room
            && ($this->user()?->can('update', $room) ?? false);
    }

    public function rules(): array
    {
        /** @var Room $room */
        $room = $this->route('room');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('rooms', 'code')->ignore($room),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'building' => ['sometimes', 'nullable', 'string', 'max:100'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:16'],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'room_type' => ['sometimes', 'required', Rule::in([
                'lecture',
                'laboratory',
                'seminar',
                'office',
                'other',
            ])],
            'status' => ['sometimes', 'required', Rule::in([
                'available',
                'unavailable',
                'maintenance',
                'retired',
            ])],
        ];
    }
}

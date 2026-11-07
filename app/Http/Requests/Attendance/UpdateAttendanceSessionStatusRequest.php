<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\ApiFormRequest;
use App\Models\AttendanceSession;
use Illuminate\Validation\Rule;

final class UpdateAttendanceSessionStatusRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof AttendanceSession
            && ($this->user()?->can('updateStatus', $session) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['submitted', 'cancelled'])],
            'cancellation_reason' => [
                Rule::requiredIf($this->input('status') === 'cancelled'),
                Rule::prohibitedIf($this->input('status') !== 'cancelled'),
                'string',
                'min:3',
                'max:2000',
            ],
        ];
    }
}

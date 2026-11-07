<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\ApiFormRequest;
use App\Models\StudentAttendance;

final class CheckInAttendanceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkIn', StudentAttendance::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:20', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}

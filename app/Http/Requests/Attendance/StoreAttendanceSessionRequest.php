<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\ApiFormRequest;
use App\Models\Role;
use App\Support\AttendanceClock;
use Closure;

final class StoreAttendanceSessionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Role::PROFESSOR) ?? false;
    }

    public function rules(): array
    {
        return [
            'class_schedule_id' => ['required', 'integer', 'exists:class_schedules,id'],
            'class_date' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value)
                        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                        && $value > AttendanceClock::today()->toDateString()) {
                        $fail('The class date must be today or earlier.');
                    }
                },
            ],
        ];
    }
}

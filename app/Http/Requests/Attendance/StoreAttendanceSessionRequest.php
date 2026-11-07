<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\ApiFormRequest;
use App\Models\Role;

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
            'class_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}

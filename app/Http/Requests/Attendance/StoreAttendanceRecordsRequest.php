<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\ApiFormRequest;
use App\Models\AttendanceSession;
use Illuminate\Validation\Rule;

final class StoreAttendanceRecordsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof AttendanceSession
            && ($this->user()?->can('record', $session) ?? false);
    }

    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_subject_enrollment_id' => [
                'required',
                'integer',
                'distinct:strict',
                'exists:student_subject_enrollments,id',
            ],
            'records.*.status' => [
                'required',
                Rule::in(['present', 'absent', 'late', 'excused']),
            ],
            'records.*.check_in_at' => ['sometimes', 'nullable', 'date_format:H:i'],
            'records.*.remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'correction_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:2000'],
        ];
    }
}

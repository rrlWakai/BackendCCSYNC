<?php

namespace App\Http\Requests\Scheduling;

use App\Models\ClassSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCandidateRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassSchedule::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_version' => [
                'required',
                'integer',
                'min:1',
            ],
            'change_summary' => [
                'required',
                'string',
                'max:1000',
            ],
            'changes' => [
                'required',
                'array',
            ],
            'changes.validation_summary' => [
                'sometimes',
                'array',
            ],
            'changes.assignments' => [
                'sometimes',
                'array',
                'max:500',
            ],
            'changes.assignments.*.meeting_instance_id' => [
                'required',
                'string',
                'max:191',
            ],
            'changes.assignments.*.source_requirement_id' => [
                'required',
                'string',
                'max:191',
            ],
            'changes.assignments.*.occurrence_number' => [
                'required',
                'integer',
                'min:1',
            ],
            'changes.assignments.*.day_of_week' => [
                'required',
                'integer',
                'between:1,5',
            ],
            'changes.assignments.*.start_time' => [
                'required',
                'date_format:H:i',
            ],
            'changes.assignments.*.end_time' => [
                'required',
                'date_format:H:i',
            ],
            'changes.assignments.*.duration_minutes' => [
                'required',
                'integer',
                'min:1',
            ],
            'changes.assignments.*.meeting_type' => [
                'required',
                'string',
                'max:16',
            ],
            'changes.assignments.*.modality' => [
                'required',
                'string',
                'max:20',
            ],
            'changes.assignments.*.is_primary' => [
                'required',
                'boolean',
                'accepted',
            ],
            'changes.assignments.*.professor_id' => [
                'required',
                'string',
                'max:191',
            ],
            'changes.assignments.*.room_id' => [
                'nullable',
                'string',
                'max:191',
            ],
            'changes.assignments.*.subject_offering_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'changes.assignments.*.subject_offering_ids.*' => [
                'string',
                'max:191',
            ],
            'changes.assignments.*.section_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'changes.assignments.*.section_ids.*' => [
                'string',
                'max:191',
            ],
            'changes.assignments.*.professor_assignments' => [
                'required',
                'array',
                'min:1',
            ],
            'changes.assignments.*.professor_assignments.*.subject_offering_id' => [
                'required',
                'string',
                'max:191',
            ],
            'changes.assignments.*.professor_assignments.*.faculty_assignment_id' => [
                'required',
                'string',
                'max:191',
            ],
            'changes.assignments.*.professor_assignments.*.assignment_role' => [
                'required',
                'string',
                Rule::in(['lead', 'co_instructor', 'lab_instructor', 'substitute']),
            ],
        ];
    }
}

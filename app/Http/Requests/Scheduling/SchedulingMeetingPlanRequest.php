<?php

namespace App\Http\Requests\Scheduling;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

final class SchedulingMeetingPlanRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'subject_offering_id' => ['required', 'integer', 'exists:subject_offerings,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'requirements' => ['required', 'array', 'min:1', 'max:20'],
            'requirements.*.meeting_type' => ['required', 'in:lecture,laboratory,seminar,other'],
            'requirements.*.frequency_per_week' => ['required', 'integer', 'between:1,14'],
            'requirements.*.duration_minutes' => ['required', 'integer', 'between:1,600'],
            'requirements.*.modality' => ['required', 'in:face_to_face,online,hybrid'],
            'requirements.*.required_room_type' => ['sometimes', 'nullable', 'in:lecture,laboratory,seminar,office,other'],
            'requirements.*.mandatory' => ['required', 'boolean'],
            'requirements.*.allowed_days' => ['sometimes', 'nullable', 'array'],
            'requirements.*.allowed_days.*' => ['integer', 'between:1,5', 'distinct'],
            'requirements.*.valid_start_times' => ['sometimes', 'nullable', 'array'],
            'requirements.*.valid_start_times.*' => ['date_format:H:i', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $requirements = (array) $this->input('requirements', []);

                if (! collect($requirements)->contains(fn ($requirement): bool => (bool) ($requirement['mandatory'] ?? false))) {
                    $validator->errors()->add('requirements', 'At least one meeting requirement must be mandatory.');
                }

                foreach ($requirements as $index => $requirement) {
                    if (($requirement['modality'] ?? null) === 'online'
                        && ($requirement['required_room_type'] ?? null) !== null) {
                        $validator->errors()->add(
                            "requirements.{$index}.required_room_type",
                            'Online requirements cannot require a physical room type.',
                        );
                    }
                }
            },
        ];
    }
}

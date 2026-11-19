<?php

namespace App\Http\Requests\Scheduling;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

final class SchedulingFullTimeWorkdayPolicyRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'standard_days' => ['required', 'array', 'min:1', 'max:5'],
            'standard_days.*' => ['integer', 'between:1,5', 'distinct'],
            'exceptions' => ['sometimes', 'array', 'max:200'],
            'exceptions.*.professor_id' => ['required', 'integer', 'exists:professors,id', 'distinct'],
            'exceptions.*.authorized_additional_days' => ['required', 'array', 'min:1', 'max:5'],
            'exceptions.*.authorized_additional_days.*' => ['integer', 'between:1,5', 'distinct'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $standardDays = collect((array) $this->input('standard_days', []))
                    ->map(fn ($day): int => (int) $day)
                    ->sort()
                    ->values()
                    ->all();

                if ($standardDays !== array_values(array_unique($standardDays))) {
                    $validator->errors()->add('standard_days', 'Standard days must be unique.');
                }

                foreach ((array) $this->input('exceptions', []) as $index => $exception) {
                    $days = collect((array) ($exception['authorized_additional_days'] ?? []))
                        ->map(fn ($day): int => (int) $day)
                        ->sort()
                        ->values()
                        ->all();

                    if ($days !== array_values(array_unique($days))) {
                        $validator->errors()->add("exceptions.{$index}.authorized_additional_days", 'Authorized additional days must be unique.');
                    }

                    if (array_intersect($days, $standardDays) !== []) {
                        $validator->errors()->add(
                            "exceptions.{$index}.authorized_additional_days",
                            'Authorized additional days must not be part of the standard workweek.',
                        );
                    }
                }
            },
        ];
    }
}

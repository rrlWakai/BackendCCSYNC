<?php

namespace App\Http\Requests\Scheduling;

use App\Http\Requests\ApiFormRequest;
use DateTimeZone;
use Illuminate\Validation\Validator;

final class SchedulingTimeGridRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'academic_term_id' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'exists:academic_terms,id'],
            'timezone' => ['required', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'days' => ['required', 'array', 'min:1', 'max:5'],
            'days.*.day_of_week' => ['required', 'integer', 'between:1,5', 'distinct'],
            'days.*.operating_start' => ['required', 'date_format:H:i'],
            'days.*.operating_end' => ['required', 'date_format:H:i'],
            'days.*.slots' => ['required', 'array', 'min:1', 'max:40'],
            'days.*.slots.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.slots.*.end_time' => ['required', 'date_format:H:i'],
            'days.*.slots.*.label' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $timezone = $this->input('timezone');

                try {
                    new DateTimeZone((string) $timezone);
                } catch (\Throwable) {
                    $validator->errors()->add('timezone', 'The timezone must be a recognized IANA timezone.');
                }

                foreach ((array) $this->input('days', []) as $dayIndex => $day) {
                    $prefix = "days.{$dayIndex}";
                    $operatingStart = (string) ($day['operating_start'] ?? '');
                    $operatingEnd = (string) ($day['operating_end'] ?? '');

                    if ($operatingStart >= $operatingEnd) {
                        $validator->errors()->add($prefix.'.operating_end', 'Operating end must be later than operating start.');
                    }

                    $previousEnd = null;
                    $starts = [];
                    $slots = (array) ($day['slots'] ?? []);
                    uasort($slots, fn (array $left, array $right): int => ((string) ($left['start_time'] ?? '')) <=> ((string) ($right['start_time'] ?? '')));

                    foreach ($slots as $slotIndex => $slot) {
                        $slotPrefix = $prefix.".slots.{$slotIndex}";
                        $start = (string) ($slot['start_time'] ?? '');
                        $end = (string) ($slot['end_time'] ?? '');

                        if ($start >= $end) {
                            $validator->errors()->add($slotPrefix.'.end_time', 'Slot end must be later than slot start.');
                        }

                        if ($start < $operatingStart || $end > $operatingEnd) {
                            $validator->errors()->add($slotPrefix.'.start_time', 'Slots must fall inside the operating hours.');
                        }

                        if ($previousEnd !== null && $start < $previousEnd) {
                            $validator->errors()->add($slotPrefix.'.start_time', 'Slots must not overlap.');
                        }

                        if (in_array($start, $starts, true)) {
                            $validator->errors()->add($slotPrefix.'.start_time', 'Slot start times must be unique within a day.');
                        }

                        $starts[] = $start;
                        $previousEnd = $previousEnd === null ? $end : max($previousEnd, $end);
                    }
                }
            },
        ];
    }
}

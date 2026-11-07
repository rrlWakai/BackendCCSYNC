<?php

namespace App\Http\Requests\Activities\Concerns;

use App\Models\Activity;
use App\Models\Role;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ValidatesActivityPayload
{
    protected function prepareActivityPayload(): void
    {
        foreach (['title', 'description', 'organizer_name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        foreach (['summary', 'venue_name', 'location_details'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            $this->normalizeLocalDateTime($field);
        }
    }

    private function normalizeLocalDateTime(string $field): void
    {
        if (! $this->exists($field) || ! is_string($this->input($field))) {
            return;
        }

        $value = trim($this->input($field));
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
            return;
        }

        $local = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $value,
            new DateTimeZone('Asia/Manila'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($local !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $this->merge([
                $field => $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('room_id') || $validator->errors()->has('venue_name')) {
                return;
            }

            $existing = $this->route('activity');
            $existing = $existing instanceof Activity ? $existing : null;
            $roomId = $this->exists('room_id') ? $this->input('room_id') : $existing?->room_id;
            $venueName = $this->exists('venue_name') ? $this->input('venue_name') : $existing?->venue_name;

            if ($roomId === null && ($venueName === null || trim((string) $venueName) === '')) {
                $validator->errors()->add('venue_name', 'Choose a room or provide an external venue name.');
            }

            if ($validator->errors()->has('starts_at') || $validator->errors()->has('ends_at')) {
                return;
            }

            $startsAt = $this->exists('starts_at')
                ? $this->input('starts_at')
                : $existing?->starts_at?->toIso8601String();
            $endsAt = $this->exists('ends_at')
                ? $this->input('ends_at')
                : $existing?->ends_at?->toIso8601String();

            if ($startsAt === null || $endsAt === null) {
                return;
            }

            if (new DateTimeImmutable($endsAt) <= new DateTimeImmutable($startsAt)) {
                $validator->errors()->add('ends_at', 'The end time must be later than the start time.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function activityRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'title' => [...$required, 'string', 'max:160'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:300'],
            'description' => [...$required, 'string', 'max:20000'],
            'category' => [...$required, Rule::in(Activity::CATEGORIES)],
            'organizer_name' => [...$required, 'string', 'max:160'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'venue_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'location_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'audience_scope' => [...$required, Rule::in(Activity::AUDIENCE_SCOPES)],
            'starts_at' => [...$required, 'date_format:Y-m-d\TH:i:sP'],
            'ends_at' => [...$required, 'date_format:Y-m-d\TH:i:sP'],
            'targets' => ['sometimes', 'array'],
            'targets.role_ids' => ['sometimes', 'array'],
            'targets.role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'targets.program_ids' => ['sometimes', 'array'],
            'targets.program_ids.*' => ['integer', 'distinct', 'exists:programs,id'],
            'targets.year_level_ids' => ['sometimes', 'array'],
            'targets.year_level_ids.*' => ['integer', 'distinct', 'exists:year_levels,id'],
            'targets.section_ids' => ['sometimes', 'array'],
            'targets.section_ids.*' => ['integer', 'distinct', 'exists:sections,id'],
            'targets.user_ids' => ['sometimes', 'array'],
            'targets.user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }
}

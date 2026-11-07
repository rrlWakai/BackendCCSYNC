<?php

namespace App\Http\Requests\Announcements\Concerns;

use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

trait ValidatesAnnouncementPayload
{
    private const DISPLAY_TIMEZONE = 'Asia/Manila';
    private const EXPIRY_AFTER_PUBLICATION_MESSAGE = 'Expiration date and time must be later than the scheduled publication date and time.';

    protected function prepareAnnouncementPayload(): void
    {
        foreach (['title', 'content'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if (is_string($this->input('summary'))) {
            $summary = trim($this->input('summary'));
            $this->merge(['summary' => $summary === '' ? null : $summary]);
        }

        $this->normalizeAnnouncementSchedule('published_at');
        $this->normalizeAnnouncementSchedule('expires_at');
    }

    private function normalizeAnnouncementSchedule(string $field): void
    {
        if (! $this->exists($field)) {
            return;
        }

        $value = $this->input($field);

        if ($value === null || $value === '') {
            $this->merge([$field => null]);

            return;
        }

        if (! is_string($value)) {
            return;
        }

        $value = trim($value);

        if ($value === '') {
            $this->merge([$field => null]);

            return;
        }

        $hasTimezone = preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', $value) === 1;
        $parsed = $hasTimezone
            ? CarbonImmutable::parse($value)
            : CarbonImmutable::parse($value, self::DISPLAY_TIMEZONE);

        $this->merge([$field => $parsed->utc()->toIso8601String()]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('published_at') || $validator->errors()->has('expires_at')) {
                return;
            }

            $existing = $this->route('announcement');
            $existing = $existing instanceof Announcement ? $existing : null;

            $publishedAt = $this->exists('published_at')
                ? $this->input('published_at')
                : $existing?->published_at;
            $expiresAt = $this->exists('expires_at')
                ? $this->input('expires_at')
                : $existing?->expires_at;

            if ($publishedAt === null || $expiresAt === null) {
                return;
            }

            $published = CarbonImmutable::parse($publishedAt);
            $expires = CarbonImmutable::parse($expiresAt);

            if (! $expires->isAfter($published)) {
                $validator->errors()->add('expires_at', self::EXPIRY_AFTER_PUBLICATION_MESSAGE);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function announcementRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'title' => [...$required, 'string', 'max:160'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:300'],
            'content' => [...$required, 'string', 'max:20000'],
            'category' => [...$required, Rule::in(Announcement::CATEGORIES)],
            'priority' => ['sometimes', 'required', Rule::in(Announcement::PRIORITIES)],
            'audience_scope' => [...$required, Rule::in(Announcement::AUDIENCE_SCOPES)],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
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

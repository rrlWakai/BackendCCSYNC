<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\Professor;
use App\Models\SchedulingFullTimeWorkdayException;
use App\Models\SchedulingFullTimeWorkdayPolicy;
use App\Models\SchedulingMeetingPlan;
use App\Models\SchedulingMeetingRequirement;
use App\Models\SchedulingTimeGrid;
use App\Models\SchedulingTimeGridDay;
use App\Models\SchedulingTimeGridSlot;
use App\Services\Scheduling\Contracts\SchedulingConfigurationProvider;
use Illuminate\Support\Collection;

final class DatabaseSchedulingConfigurationProvider implements SchedulingConfigurationProvider
{
    public function timeGrid(AcademicTerm $term): ?array
    {
        $grid = SchedulingTimeGrid::query()
            ->where('academic_term_id', $term->id)
            ->where('status', SchedulingTimeGrid::STATUS_APPROVED)
            ->with(['days.slots'])
            ->orderByDesc('version')
            ->first();

        if ($grid === null || $grid->approved_at === null) {
            return null;
        }

        return [
            'configuration_id' => (string) $grid->id,
            'version' => (int) $grid->version,
            'academic_term_id' => (string) $grid->academic_term_id,
            'approval_status' => 'approved',
            'approved_at' => $grid->approved_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'timezone' => (string) $grid->timezone,
            'days' => $grid->days
                ->sortBy('day_of_week')
                ->map(fn (SchedulingTimeGridDay $day): array => [
                    'day_of_week' => (int) $day->day_of_week,
                    'operating_start' => substr((string) $day->operating_start, 0, 5),
                    'operating_end' => substr((string) $day->operating_end, 0, 5),
                    'valid_start_times' => $day->slots
                        ->map(fn (SchedulingTimeGridSlot $slot): string => substr((string) $slot->start_time, 0, 5))
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function meetingPlans(AcademicTerm $term, Collection $offerings): array
    {
        if ($offerings->isEmpty()) {
            return [];
        }

        $offeringIds = $offerings->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $plans = SchedulingMeetingPlan::query()
            ->where('academic_term_id', $term->id)
            ->whereIn('subject_offering_id', $offeringIds)
            ->where('status', SchedulingMeetingPlan::STATUS_APPROVED)
            ->with('requirements')
            ->orderByDesc('version')
            ->get()
            ->unique('subject_offering_id')
            ->keyBy('subject_offering_id');

        $payload = [];

        foreach ($plans as $offeringId => $plan) {
            if ($plan->approved_at === null) {
                continue;
            }

            $payload[(int) $offeringId] = [
                'meeting_plan_id' => (string) $plan->id,
                'meeting_plan_version' => (int) $plan->version,
                'meeting_plan_approved_at' => $plan->approved_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'meeting_requirements' => $plan->requirements
                    ->map(fn (SchedulingMeetingRequirement $requirement): array => [
                        'id' => (string) $requirement->id,
                        'meeting_type' => (string) $requirement->meeting_type,
                        'frequency_per_week' => (int) $requirement->frequency_per_week,
                        'duration_minutes' => (int) $requirement->duration_minutes,
                        'modality' => (string) $requirement->modality,
                        'required_room_type' => $requirement->required_room_type,
                        'mandatory' => (bool) $requirement->mandatory,
                        'allowed_days' => $requirement->allowed_days === null
                            ? null
                            : collect($requirement->allowed_days)->map(fn ($day): int => (int) $day)->unique()->sort()->values()->all(),
                        'valid_start_times' => $requirement->valid_start_times === null
                            ? null
                            : collect($requirement->valid_start_times)->map(fn ($time): string => (string) $time)->unique()->sort()->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $payload;
    }

    public function fullTimeWorkdayPolicy(AcademicTerm $term, Collection $professors): ?array
    {
        $policy = SchedulingFullTimeWorkdayPolicy::query()
            ->where('academic_term_id', $term->id)
            ->where('status', SchedulingFullTimeWorkdayPolicy::STATUS_APPROVED)
            ->with('exceptions')
            ->orderByDesc('version')
            ->first();

        if ($policy === null || $policy->approved_at === null) {
            return null;
        }

        return [
            'policy_id' => (string) $policy->id,
            'policy_version' => (int) $policy->version,
            'academic_term_id' => (string) $policy->academic_term_id,
            'standard_days' => collect($policy->standard_days)
                ->map(fn ($day): int => (int) $day)
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'exceptions' => $policy->exceptions
                ->map(fn (SchedulingFullTimeWorkdayException $exception): array => [
                    'professor_id' => (string) $exception->professor_id,
                    'authorized_additional_days' => collect($exception->authorized_additional_days)
                        ->map(fn ($day): int => (int) $day)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function partTimeWeeklyTargetMinutes(Professor $professor): ?int
    {
        if ($professor->employment_type !== 'part_time'
            || $professor->weekly_teaching_hours_quota === null) {
            return null;
        }

        $minutes = (int) round((float) $professor->weekly_teaching_hours_quota * 60);

        return $minutes >= 0 ? $minutes : null;
    }

    public function sectionAvailabilityWindows(AcademicTerm $term, Collection $sections): array
    {
        return [];
    }

    public function combinedClasses(AcademicTerm $term): array
    {
        return [];
    }

    public function capabilityStatus(): array
    {
        return [
            'section_availability' => [
                'supported' => false,
                'reason' => 'No section availability tables or approval workflow exist. Contract v1.0 sends empty availability windows.',
                'contract_v1_behavior' => 'empty_availability_windows',
            ],
            'combined_classes' => [
                'supported' => false,
                'reason' => 'No combined-class configuration tables or approval workflow exist. Contract v1.0 sends an empty combined_classes list.',
                'contract_v1_behavior' => 'empty_combined_classes',
            ],
            'fixed_schedule_ids' => [
                'supported' => false,
                'reason' => 'No fixed-schedule selection workflow exists. Contract v1.0 sends an empty fixed_schedule_ids list.',
                'contract_v1_behavior' => 'empty_fixed_schedule_ids',
            ],
            'replacement_draft_schedule_ids' => [
                'supported' => false,
                'reason' => 'Draft schedules are preserved rather than replaced in Contract v1.0.',
                'contract_v1_behavior' => 'empty_replacement_draft_schedule_ids',
            ],
            'existing_commitments' => [
                'supported' => false,
                'reason' => 'No approved external commitment workflow exists. Contract v1.0 sends an empty existing_commitments list.',
                'contract_v1_behavior' => 'empty_existing_commitments',
            ],
        ];
    }
}

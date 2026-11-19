<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AcademicTerm;
use App\Models\Professor;
use App\Services\Scheduling\Contracts\SchedulingConfigurationProvider;
use Illuminate\Support\Collection;

final class FakeSchedulingConfigurationProvider implements SchedulingConfigurationProvider
{
    /**
     * @param  array<string, mixed>|null  $timeGrid
     * @param  array<int|string, array<string, mixed>>  $meetingPlans
     * @param  array<string, mixed>|null  $fullTimePolicy
     */
    public function __construct(
        private readonly ?array $timeGrid,
        private readonly array $meetingPlans,
        private readonly ?array $fullTimePolicy = null,
    ) {}

    public function timeGrid(AcademicTerm $term): ?array
    {
        return $this->timeGrid;
    }

    public function meetingPlans(AcademicTerm $term, Collection $offerings): array
    {
        return $this->meetingPlans;
    }

    public function fullTimeWorkdayPolicy(AcademicTerm $term, Collection $professors): ?array
    {
        return $this->fullTimePolicy;
    }

    public function partTimeWeeklyTargetMinutes(Professor $professor): ?int
    {
        return null;
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
                'reason' => 'The fake provider does not expose section availability. Contract v1.0 sends empty availability windows.',
                'contract_v1_behavior' => 'empty_availability_windows',
            ],
            'combined_classes' => [
                'supported' => false,
                'reason' => 'The fake provider does not expose combined classes. Contract v1.0 sends an empty combined_classes list.',
                'contract_v1_behavior' => 'empty_combined_classes',
            ],
            'fixed_schedule_ids' => [
                'supported' => false,
                'reason' => 'The fake provider does not expose fixed schedules. Contract v1.0 sends an empty fixed_schedule_ids list.',
                'contract_v1_behavior' => 'empty_fixed_schedule_ids',
            ],
            'replacement_draft_schedule_ids' => [
                'supported' => false,
                'reason' => 'The fake provider does not expose replacement drafts. Contract v1.0 sends an empty replacement_draft_schedule_ids list.',
                'contract_v1_behavior' => 'empty_replacement_draft_schedule_ids',
            ],
            'existing_commitments' => [
                'supported' => false,
                'reason' => 'The fake provider does not expose existing commitments. Contract v1.0 sends an empty existing_commitments list.',
                'contract_v1_behavior' => 'empty_existing_commitments',
            ],
        ];
    }
}

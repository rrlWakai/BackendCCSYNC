<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\AcademicTerm;
use App\Models\Professor;
use App\Services\Scheduling\Contracts\SchedulingConfigurationProvider;
use Illuminate\Support\Collection;

final class NullSchedulingConfigurationProvider implements SchedulingConfigurationProvider
{
    public function timeGrid(AcademicTerm $term): ?array
    {
        return null;
    }

    public function meetingPlans(AcademicTerm $term, Collection $offerings): array
    {
        return [];
    }

    public function fullTimeWorkdayPolicy(AcademicTerm $term, Collection $professors): ?array
    {
        return null;
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
                'reason' => 'No scheduling configuration provider is active. Contract v1.0 sends empty availability windows.',
                'contract_v1_behavior' => 'empty_availability_windows',
            ],
            'combined_classes' => [
                'supported' => false,
                'reason' => 'No scheduling configuration provider is active. Contract v1.0 sends an empty combined_classes list.',
                'contract_v1_behavior' => 'empty_combined_classes',
            ],
            'fixed_schedule_ids' => [
                'supported' => false,
                'reason' => 'No scheduling configuration provider is active. Contract v1.0 sends an empty fixed_schedule_ids list.',
                'contract_v1_behavior' => 'empty_fixed_schedule_ids',
            ],
            'replacement_draft_schedule_ids' => [
                'supported' => false,
                'reason' => 'No scheduling configuration provider is active. Contract v1.0 sends an empty replacement_draft_schedule_ids list.',
                'contract_v1_behavior' => 'empty_replacement_draft_schedule_ids',
            ],
            'existing_commitments' => [
                'supported' => false,
                'reason' => 'No scheduling configuration provider is active. Contract v1.0 sends an empty existing_commitments list.',
                'contract_v1_behavior' => 'empty_existing_commitments',
            ],
        ];
    }
}

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
}

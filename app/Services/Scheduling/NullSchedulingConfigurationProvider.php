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
}

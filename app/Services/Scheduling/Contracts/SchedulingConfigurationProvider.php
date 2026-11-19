<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Contracts;

use App\Models\AcademicTerm;
use App\Models\Professor;
use App\Models\Section;
use App\Models\SubjectOffering;
use Illuminate\Support\Collection;

interface SchedulingConfigurationProvider
{
    /**
     * @return array<string, mixed>|null
     */
    public function timeGrid(AcademicTerm $term): ?array;

    /**
     * @param  Collection<int, SubjectOffering>  $offerings
     * @return array<int|string, array<string, mixed>>
     */
    public function meetingPlans(AcademicTerm $term, Collection $offerings): array;

    /**
     * @param  Collection<int, Professor>  $professors
     * @return array<string, mixed>|null
     */
    public function fullTimeWorkdayPolicy(AcademicTerm $term, Collection $professors): ?array;

    public function partTimeWeeklyTargetMinutes(Professor $professor): ?int;

    /**
     * @param  Collection<int, Section>  $sections
     * @return array<int|string, list<array<string, mixed>>>
     */
    public function sectionAvailabilityWindows(AcademicTerm $term, Collection $sections): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function combinedClasses(AcademicTerm $term): array;

    public function capabilityStatus(): array;
}

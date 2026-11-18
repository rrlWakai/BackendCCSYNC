<?php

namespace App\Services\Academic;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\StudentAcademicPlacement;
use Illuminate\Support\Collection;

final class SectionAllocationService
{
    /**
     * @return Collection<int, Section>
     */
    public function sectionsFor(
        AcademicTerm $term,
        int $programId,
        int $yearLevelId,
        ?int $majorId,
        bool $lock = false,
    ): Collection {
        return Section::query()
            ->where('academic_term_id', $term->id)
            ->where('program_id', $programId)
            ->where('year_level_id', $yearLevelId)
            ->when(
                $majorId === null,
                fn ($query) => $query->whereNull('major_id'),
                fn ($query) => $query->where('major_id', $majorId),
            )
            ->where('status', 'active')
            ->orderBy('code')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    public function occupiedSeats(Section $section, AcademicTerm $term): int
    {
        return StudentAcademicPlacement::query()
            ->where('section_id', $section->id)
            ->where('academic_term_id', $term->id)
            ->where('status', 'active')
            ->count();
    }

    public function remainingSeats(Section $section, AcademicTerm $term): ?int
    {
        if ($section->capacity === null) {
            return null;
        }

        return max(0, $section->capacity - $this->occupiedSeats($section, $term));
    }

    /**
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     totals: array{students: int, required_sections: int, existing_sections: int, shortage_sections: int, shortage_seats: int}
     * }
     */
    public function planForTerm(
        AcademicTerm $term,
        ?int $programId = null,
        ?int $yearLevelId = null,
        ?int $majorId = null,
    ): array {
        $placements = StudentAcademicPlacement::query()
            ->where('academic_term_id', $term->id)
            ->where('status', 'active')
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->when($yearLevelId !== null, fn ($query) => $query->where('year_level_id', $yearLevelId))
            ->when($majorId !== null, fn ($query) => $query->where('major_id', $majorId))
            ->get(['id', 'program_id', 'year_level_id', 'major_id']);

        $groups = $placements
            ->groupBy(fn (StudentAcademicPlacement $placement): string => sprintf(
                '%d:%d:%s',
                $placement->program_id,
                $placement->year_level_id,
                $placement->major_id ?? 'none',
            ))
            ->map(function (Collection $group) use ($term): array {
                /** @var StudentAcademicPlacement $first */
                $first = $group->first();
                $sections = $this->sectionsFor(
                    $term,
                    (int) $first->program_id,
                    (int) $first->year_level_id,
                    $first->major_id === null ? null : (int) $first->major_id,
                );

                $occupied = $sections->mapWithKeys(fn (Section $section): array => [
                    $section->id => $this->occupiedSeats($section, $term),
                ])->all();

                return $this->summarizeGroup(
                    (int) $first->program_id,
                    (int) $first->year_level_id,
                    $first->major_id === null ? null : (int) $first->major_id,
                    $group->count(),
                    $sections,
                    $occupied,
                );
            })
            ->values()
            ->all();

        $totals = [
            'students' => array_sum(array_column($groups, 'student_count')),
            'required_sections' => array_sum(array_column($groups, 'required_sections')),
            'existing_sections' => array_sum(array_column($groups, 'existing_sections')),
            'shortage_sections' => array_sum(array_column($groups, 'shortage_sections')),
            'shortage_seats' => array_sum(array_column($groups, 'shortage_seats')),
        ];

        return ['groups' => $groups, 'totals' => $totals];
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @param  array<int, int>  $occupiedSeats
     * @return array<string, mixed>
     */
    public function summarizeGroup(
        int $programId,
        int $yearLevelId,
        ?int $majorId,
        int $studentCount,
        Collection $sections,
        array $occupiedSeats = [],
    ): array {
        $capacities = $sections
            ->pluck('capacity')
            ->filter(fn ($capacity): bool => $capacity !== null)
            ->map(fn ($capacity): int => (int) $capacity)
            ->values();

        $referenceCapacity = $capacities->isEmpty() ? null : (int) $capacities->max();
        $requiredSections = $referenceCapacity === null
            ? 0
            : (int) ceil($studentCount / $referenceCapacity);

        $capacityConfigured = $sections->every(fn (Section $section): bool => $section->capacity !== null);
        $hasUnlimitedSection = $sections->contains(fn (Section $section): bool => $section->capacity === null);
        $occupiedTotal = $sections->sum(fn (Section $section): int => $occupiedSeats[$section->id] ?? 0);
        $availableSeats = $sections->sum(fn (Section $section): int => $section->capacity === null
            ? 0
            : max(0, $section->capacity - ($occupiedSeats[$section->id] ?? 0)));
        $shortageSeats = $hasUnlimitedSection ? 0 : max(0, $studentCount - $availableSeats);

        return [
            'program_id' => $programId,
            'year_level_id' => $yearLevelId,
            'major_id' => $majorId,
            'student_count' => $studentCount,
            'reference_capacity' => $referenceCapacity,
            'required_sections' => $requiredSections,
            'existing_sections' => $sections->count(),
            'shortage_sections' => max(0, $requiredSections - $sections->count()),
            'shortage_seats' => $shortageSeats,
            'capacity_configured' => $capacityConfigured,
            'occupied_seats' => $occupiedTotal,
            'available_seats' => $hasUnlimitedSection ? null : $availableSeats,
            'sections' => $sections->map(fn (Section $section): array => [
                'id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'capacity' => $section->capacity,
                'occupied_seats' => $occupiedSeats[$section->id] ?? 0,
                'available_seats' => $section->capacity === null
                    ? null
                    : max(0, $section->capacity - ($occupiedSeats[$section->id] ?? 0)),
            ])->values()->all(),
        ];
    }
}

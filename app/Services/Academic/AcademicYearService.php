<?php

namespace App\Services\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;

final class AcademicYearService
{
    public function resolveForTerm(AcademicTerm $term): AcademicYear
    {
        if ($term->academic_year_id !== null) {
            return AcademicYear::query()->findOrFail($term->academic_year_id);
        }

        return $this->resolve(
            $term->academic_year,
            $term->starts_on?->toDateString() ?? now()->toDateString(),
            $term->ends_on?->toDateString() ?? now()->addYear()->toDateString(),
            $term->is_current ? 'active' : 'planned',
        );
    }

    public function resolve(
        string $label,
        string $startsOn,
        string $endsOn,
        string $status = 'planned',
    ): AcademicYear {
        $year = AcademicYear::query()->firstOrCreate(
            ['label' => $label],
            [
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => $status,
            ],
        );

        if ($year->wasRecentlyCreated) {
            return $year;
        }

        $updates = [];
        if ($year->starts_on->toDateString() > $startsOn) {
            $updates['starts_on'] = $startsOn;
        }
        if ($year->ends_on->toDateString() < $endsOn) {
            $updates['ends_on'] = $endsOn;
        }

        if ($updates !== []) {
            $year->update($updates);
        }

        return $year->refresh();
    }
}

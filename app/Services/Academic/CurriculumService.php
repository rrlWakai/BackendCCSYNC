<?php

namespace App\Services\Academic;

use App\Models\CurriculumRequirement;
use App\Models\Major;
use App\Models\Program;
use App\Models\Subject;
use App\Models\YearLevel;
use Illuminate\Database\Eloquent\Collection;

final class CurriculumService
{
    /**
     * @return Collection<int, Subject>
     */
    public function subjectsFor(Program $program, YearLevel $yearLevel, ?Major $major = null): Collection
    {
        $requirements = CurriculumRequirement::query()
            ->with('subject')
            ->where('program_id', $program->id)
            ->where('year_level_id', $yearLevel->id)
            ->where('status', 'active')
            ->when(
                $major === null,
                fn ($query) => $query->whereNull('major_id'),
                fn ($query) => $query->where(fn ($nested) => $nested
                    ->whereNull('major_id')
                    ->orWhere('major_id', $major->id)),
            )
            ->orderBy('requirement_type')
            ->orderBy('id')
            ->get();

        return $requirements
            ->pluck('subject')
            ->filter(fn (?Subject $subject): bool => $subject !== null && $subject->status === 'active')
            ->unique('id')
            ->values();
    }
}

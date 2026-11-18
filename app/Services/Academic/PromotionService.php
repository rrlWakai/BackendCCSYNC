<?php

namespace App\Services\Academic;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Major;
use App\Models\PromotionRun;
use App\Models\PromotionRunItem;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentAcademicPlacement;
use App\Models\StudentMajorSelection;
use App\Models\User;
use App\Models\YearLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PromotionService
{
    private const MAJOR_START_LEVEL = 3;

    public function __construct(private readonly SectionAllocationService $sections) {}

    public function prepare(
        AcademicYear $sourceYear,
        AcademicTerm $sourceTerm,
        AcademicYear $targetYear,
        AcademicTerm $targetTerm,
        User $actor,
    ): PromotionRun {
        if ((int) $sourceTerm->academic_year_id !== (int) $sourceYear->id) {
            throw ValidationException::withMessages([
                'source_term_id' => 'The source term must belong to the source academic year.',
            ]);
        }

        if ((int) $targetTerm->academic_year_id !== (int) $targetYear->id) {
            throw ValidationException::withMessages([
                'target_term_id' => 'The target term must belong to the target academic year.',
            ]);
        }

        if ($sourceTerm->term !== 'second') {
            throw ValidationException::withMessages([
                'source_term_id' => 'Promotion can only be prepared from the Second Semester.',
            ]);
        }

        if (! $sourceTerm->ends_on->lt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'source_term_id' => 'Promotion can only be prepared after the Second Semester has ended.',
            ]);
        }

        if ((int) $sourceYear->id === (int) $targetYear->id) {
            throw ValidationException::withMessages([
                'target_academic_year_id' => 'Choose a different target academic year.',
            ]);
        }

        return DB::transaction(function () use ($sourceYear, $sourceTerm, $targetYear, $targetTerm, $actor): PromotionRun {
            $existing = PromotionRun::query()
                ->where('source_academic_year_id', $sourceYear->id)
                ->where('source_term_id', $sourceTerm->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'source_term_id' => $existing->status === 'finalized'
                        ? 'Promotion for this academic year has already been finalized.'
                        : 'A promotion run for this academic year already exists.',
                ]);
            }

            $run = PromotionRun::create([
                'source_academic_year_id' => $sourceYear->id,
                'target_academic_year_id' => $targetYear->id,
                'source_term_id' => $sourceTerm->id,
                'target_term_id' => $targetTerm->id,
                'status' => 'prepared',
                'prepared_by' => $actor->id,
                'prepared_at' => now(),
            ]);

            $placements = StudentAcademicPlacement::query()
                ->with(['student', 'program', 'yearLevel', 'major'])
                ->where('academic_year_id', $sourceYear->id)
                ->where('is_current', true)
                ->where('status', 'active')
                ->whereHas('student', fn ($query) => $query->where('status', 'active'))
                ->orderBy('student_id')
                ->get();

            foreach ($placements as $placement) {
                $this->prepareItem($run, $placement, $targetYear, $targetTerm);
            }

            return $run->refresh()->load($this->runRelations());
        }, attempts: 3);
    }

    public function adjustItem(
        PromotionRun $run,
        PromotionRunItem $item,
        array $attributes,
    ): PromotionRunItem {
        if ((int) $item->promotion_run_id !== (int) $run->id) {
            throw ValidationException::withMessages([
                'item' => 'The selected student does not belong to this promotion run.',
            ]);
        }

        if ($run->status !== 'prepared') {
            throw ValidationException::withMessages([
                'run' => 'Only prepared promotion runs can be adjusted.',
            ]);
        }

        return DB::transaction(function () use ($run, $item, $attributes): PromotionRunItem {
            $item = PromotionRunItem::query()->lockForUpdate()->findOrFail($item->id);
            $fromPlacement = StudentAcademicPlacement::query()->findOrFail($item->from_placement_id);

            $toYearLevelId = array_key_exists('to_year_level_id', $attributes)
                ? ($attributes['to_year_level_id'] === null ? null : (int) $attributes['to_year_level_id'])
                : $item->to_year_level_id;

            if ($toYearLevelId !== null) {
                $yearLevel = YearLevel::query()->findOrFail($toYearLevelId);
                $fromLevel = YearLevel::query()->findOrFail($item->from_year_level_id);

                if ($yearLevel->level <= $fromLevel->level) {
                    throw ValidationException::withMessages([
                        'to_year_level_id' => 'The target year level must be higher than the current year level.',
                    ]);
                }
            }

            $majorId = array_key_exists('major_id', $attributes)
                ? ($attributes['major_id'] === null ? null : (int) $attributes['major_id'])
                : $item->major_id;

            if ($majorId !== null) {
                $major = Major::query()->findOrFail($majorId);
                if ((int) $major->program_id !== (int) $fromPlacement->program_id) {
                    throw ValidationException::withMessages([
                        'major_id' => 'The selected major does not belong to the student program.',
                    ]);
                }
            }

            $sectionId = array_key_exists('section_id', $attributes)
                ? ($attributes['section_id'] === null ? null : (int) $attributes['section_id'])
                : $item->section_id;

            if ($sectionId !== null) {
                $section = Section::query()->findOrFail($sectionId);
                $this->assertSectionMatches($run, $section, (int) $fromPlacement->program_id, $toYearLevelId, $majorId);

                $occupied = $this->sections->occupiedSeats($section, $run->targetTerm)
                    + PromotionRunItem::query()
                        ->where('promotion_run_id', $run->id)
                        ->whereKeyNot($item->id)
                        ->where('section_id', $section->id)
                        ->whereIn('status', ['ready', 'promoted'])
                        ->count();

                if ($section->capacity !== null && $occupied >= $section->capacity) {
                    throw ValidationException::withMessages([
                        'section_id' => 'The selected section is already at capacity.',
                    ]);
                }
            }

            $exception = $toYearLevelId === null
                ? ['exception_code' => 'terminal_year_level', 'exception_message' => 'No higher year level is configured.']
                : ['exception_code' => null, 'exception_message' => null];

            $item->update([
                'to_year_level_id' => $toYearLevelId,
                'major_id' => $majorId,
                'section_id' => $sectionId,
                'status' => $exception['exception_code'] === null ? 'ready' : 'exception',
                ...$exception,
            ]);

            return $item->refresh()->load(['fromPlacement', 'fromYearLevel', 'toYearLevel', 'major', 'section', 'student']);
        }, attempts: 3);
    }

    /**
     * @return array{run: PromotionRun, allocation: array<string, mixed>}
     */
    public function allocate(PromotionRun $run): array
    {
        if ($run->status !== 'prepared') {
            throw ValidationException::withMessages([
                'run' => 'Only prepared promotion runs can be allocated.',
            ]);
        }

        return DB::transaction(function () use ($run): array {
            $run = PromotionRun::query()->lockForUpdate()->findOrFail($run->id);
            $run->load(['items.fromPlacement', 'targetTerm']);

            $items = $run->items
                ->filter(fn (PromotionRunItem $item): bool => $item->to_year_level_id !== null)
                ->groupBy(fn (PromotionRunItem $item): string => sprintf(
                    '%d:%d:%s',
                    $item->fromPlacement->program_id,
                    $item->to_year_level_id,
                    $item->major_id ?? 'none',
                ));

            $groups = [];

            foreach ($items as $group) {
                /** @var PromotionRunItem $first */
                $first = $group->first();
                $programId = (int) $first->fromPlacement->program_id;
                $yearLevelId = (int) $first->to_year_level_id;
                $majorId = $first->major_id === null ? null : (int) $first->major_id;

                $sections = $this->sections->sectionsFor($run->targetTerm, $programId, $yearLevelId, $majorId);
                $summary = $this->sections->summarizeGroup(
                    $programId,
                    $yearLevelId,
                    $majorId,
                    $group->count(),
                    $sections,
                );

                $remaining = $sections->mapWithKeys(fn (Section $section): array => [
                    $section->id => $this->remainingCapacity($section, $run),
                ]);

                foreach ($group as $item) {
                    $assigned = null;

                    foreach ($sections as $section) {
                        $left = $remaining[$section->id] ?? 0;

                        if ($left === null || $left > 0) {
                            $assigned = $section;
                            if ($left !== null) {
                                $remaining[$section->id] = $left - 1;
                            }

                            break;
                        }
                    }

                    if ($assigned === null) {
                        $item->update([
                            'section_id' => null,
                            'status' => 'exception',
                            'exception_code' => 'section_shortage',
                            'exception_message' => 'No section has enough remaining capacity for this student.',
                        ]);

                        continue;
                    }

                    $item->update([
                        'section_id' => $assigned->id,
                        'status' => 'ready',
                        'exception_code' => null,
                        'exception_message' => null,
                    ]);
                }

                $groups[] = $summary;
            }

            return [
                'run' => $run->refresh()->load($this->runRelations()),
                'allocation' => [
                    'groups' => $groups,
                    'totals' => [
                        'students' => array_sum(array_column($groups, 'student_count')),
                        'required_sections' => array_sum(array_column($groups, 'required_sections')),
                        'existing_sections' => array_sum(array_column($groups, 'existing_sections')),
                        'shortage_sections' => array_sum(array_column($groups, 'shortage_sections')),
                        'shortage_seats' => array_sum(array_column($groups, 'shortage_seats')),
                    ],
                ],
            ];
        }, attempts: 3);
    }

    public function finalize(PromotionRun $run, User $actor, bool $allowShortages = false): PromotionRun
    {
        if ($run->status !== 'prepared') {
            throw ValidationException::withMessages([
                'run' => 'Only prepared promotion runs can be finalized.',
            ]);
        }

        return DB::transaction(function () use ($run, $actor, $allowShortages): PromotionRun {
            $run = PromotionRun::query()
                ->with(['items', 'targetTerm', 'targetAcademicYear'])
                ->lockForUpdate()
                ->findOrFail($run->id);

            $exceptions = $run->items->where('status', 'exception');
            if ($exceptions->isNotEmpty() && ! $allowShortages) {
                throw ValidationException::withMessages([
                    'run' => "This run has {$exceptions->count()} unresolved exception(s). Resolve them or finalize with shortages.",
                ]);
            }

            $promoted = 0;

            foreach ($run->items as $item) {
                if ($item->status === 'exception' || $item->to_year_level_id === null || $item->section_id === null) {
                    continue;
                }

                $fromPlacement = StudentAcademicPlacement::query()
                    ->lockForUpdate()
                    ->findOrFail($item->from_placement_id);

                $alreadyPlaced = StudentAcademicPlacement::query()
                    ->where('student_id', $item->student_id)
                    ->where('academic_term_id', $run->target_term_id)
                    ->exists();

                if ($alreadyPlaced) {
                    $item->update([
                        'status' => 'exception',
                        'exception_code' => 'already_placed',
                        'exception_message' => 'The student already has a placement for the target term.',
                    ]);

                    continue;
                }

                $placement = StudentAcademicPlacement::create([
                    'student_id' => $item->student_id,
                    'academic_year_id' => $run->target_academic_year_id,
                    'academic_term_id' => $run->target_term_id,
                    'program_id' => $fromPlacement->program_id,
                    'year_level_id' => $item->to_year_level_id,
                    'major_id' => $item->major_id,
                    'section_id' => $item->section_id,
                    'status' => 'active',
                    'is_current' => true,
                    'effective_on' => $run->targetTerm->starts_on?->toDateString(),
                    'promotion_run_id' => $run->id,
                    'promoted_from_placement_id' => $fromPlacement->id,
                ]);

                $fromPlacement->update([
                    'is_current' => false,
                    'ends_on' => $run->targetTerm->starts_on?->copy()->subDay()->toDateString(),
                ]);

                $student = Student::query()->lockForUpdate()->findOrFail($item->student_id);
                $student->forceFill([
                    'current_placement_id' => $placement->id,
                    'section_id' => $placement->section_id,
                ])->save();

                $item->update(['status' => 'promoted', 'exception_code' => null, 'exception_message' => null]);
                $promoted++;
            }

            $run->update([
                'status' => 'finalized',
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ]);

            $run = $run->refresh()->load($this->runRelations());
            $run->setAttribute('promoted_count', $promoted);

            return $run;
        }, attempts: 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function duePromotions(): array
    {
        $terms = AcademicTerm::query()
            ->where('term', 'second')
            ->whereDate('ends_on', '<', now()->toDateString())
            ->with('academicYear')
            ->orderBy('ends_on')
            ->get();

        return $terms->map(function (AcademicTerm $term): array {
            $existing = PromotionRun::query()
                ->where('source_academic_year_id', $term->academic_year_id)
                ->where('source_term_id', $term->id)
                ->first();

            return [
                'academic_year_id' => $term->academic_year_id,
                'academic_year' => $term->academicYear?->label,
                'source_term_id' => $term->id,
                'source_term' => $term->name,
                'ends_on' => $term->ends_on?->toDateString(),
                'status' => $existing?->status ?? 'not_prepared',
                'promotion_run_id' => $existing?->id,
            ];
        })->values()->all();
    }

    private function prepareItem(
        PromotionRun $run,
        StudentAcademicPlacement $placement,
        AcademicYear $targetYear,
        AcademicTerm $targetTerm,
    ): void {
        $fromYearLevel = $placement->yearLevel;
        $toYearLevel = YearLevel::query()
            ->where('level', '>', $fromYearLevel->level)
            ->orderBy('level')
            ->first();

        $majorId = $placement->major_id;
        $exceptionCode = null;
        $exceptionMessage = null;

        if ($toYearLevel === null) {
            $exceptionCode = 'terminal_year_level';
            $exceptionMessage = 'No higher year level is configured for promotion.';
        } elseif ($majorId === null && $toYearLevel->level >= self::MAJOR_START_LEVEL && $this->programHasMajors((int) $placement->program_id)) {
            $selection = StudentMajorSelection::query()
                ->where('student_id', $placement->student_id)
                ->where('academic_year_id', $targetYear->id)
                ->first();

            if ($selection !== null && $selection->status === 'approved') {
                $majorId = $selection->major_id;
            } elseif ($selection !== null && $selection->status === 'submitted') {
                $exceptionCode = 'major_selection_pending';
                $exceptionMessage = 'The student major selection is awaiting review.';
            } else {
                $fallback = StudentMajorSelection::query()
                    ->where('student_id', $placement->student_id)
                    ->where('status', 'approved')
                    ->latest('academic_year_id')
                    ->first();

                if ($fallback !== null) {
                    $majorId = $fallback->major_id;
                } else {
                    $exceptionCode = 'major_required';
                    $exceptionMessage = 'The student must select a major before promotion.';
                }
            }
        }

        if (StudentAcademicPlacement::query()
            ->where('student_id', $placement->student_id)
            ->where('academic_term_id', $targetTerm->id)
            ->exists()) {
            $exceptionCode = 'already_placed';
            $exceptionMessage = 'The student already has a placement for the target term.';
        }

        PromotionRunItem::create([
            'promotion_run_id' => $run->id,
            'student_id' => $placement->student_id,
            'from_placement_id' => $placement->id,
            'from_year_level_id' => $placement->year_level_id,
            'to_year_level_id' => $toYearLevel?->id,
            'major_id' => $majorId,
            'section_id' => null,
            'status' => $exceptionCode === null ? 'ready' : 'exception',
            'exception_code' => $exceptionCode,
            'exception_message' => $exceptionMessage,
        ]);
    }

    private function programHasMajors(int $programId): bool
    {
        return Major::query()
            ->where('program_id', $programId)
            ->where('status', 'active')
            ->exists();
    }

    private function remainingCapacity(
        Section $section,
        PromotionRun $run,
    ): ?int {
        if ($section->capacity === null) {
            return null;
        }

        $occupied = $this->sections->occupiedSeats($section, $run->targetTerm);

        return max(0, $section->capacity - $occupied);
    }

    private function assertSectionMatches(
        PromotionRun $run,
        Section $section,
        int $programId,
        ?int $yearLevelId,
        ?int $majorId,
    ): void {
        if ((int) $section->academic_term_id !== (int) $run->target_term_id) {
            throw ValidationException::withMessages([
                'section_id' => 'The section must belong to the target term.',
            ]);
        }

        if ((int) $section->program_id !== $programId) {
            throw ValidationException::withMessages([
                'section_id' => 'The section must belong to the student program.',
            ]);
        }

        if ($yearLevelId !== null && (int) $section->year_level_id !== $yearLevelId) {
            throw ValidationException::withMessages([
                'section_id' => 'The section must match the target year level.',
            ]);
        }

        if ((int) ($section->major_id ?? 0) !== (int) ($majorId ?? 0)) {
            throw ValidationException::withMessages([
                'section_id' => 'The section major must match the student major.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function runRelations(): array
    {
        return [
            'sourceAcademicYear',
            'targetAcademicYear',
            'sourceTerm',
            'targetTerm',
            'items.student.user:id,name,email',
            'items.fromPlacement',
            'items.fromYearLevel',
            'items.toYearLevel',
            'items.major',
            'items.section',
        ];
    }
}

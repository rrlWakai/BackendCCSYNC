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
use App\Services\Students\StudentLifecycleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PromotionService
{
    private const MAJOR_START_LEVEL = 3;

    private const BLOCKING_EXCEPTIONS = [
        'terminal_year_level',
        'already_placed',
        'major_required',
        'not_eligible',
        'source_placement_changed',
        'student_inactive',
    ];

    public function __construct(
        private readonly SectionAllocationService $sections,
        private readonly StudentLifecycleService $lifecycle,
        private readonly AcademicYearService $academicYears,
    ) {}

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

            $placements = $this->sourcePlacements($sourceYear);

            foreach ($placements as $placement) {
                $this->prepareItem($run, $placement, $sourceYear, $targetYear, $targetTerm);
            }

            return $run->refresh()->load($this->runRelations());
        }, attempts: 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function processDue(?User $actor = null): array
    {
        $terms = AcademicTerm::query()
            ->where('term', 'second')
            ->whereDate('ends_on', '<', now()->toDateString())
            ->with('academicYear')
            ->orderBy('ends_on')
            ->get();

        return $terms
            ->map(fn (AcademicTerm $term): array => $this->processDueTerm($term, $actor))
            ->values()
            ->all();
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

            if (
                ! array_key_exists('section_id', $attributes)
                && (array_key_exists('major_id', $attributes) || array_key_exists('to_year_level_id', $attributes))
            ) {
                $sectionId = null;
            }

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
            $run->load(['items.fromPlacement', 'targetTerm', 'targetAcademicYear']);

            $items = $run->items
                ->filter(fn (PromotionRunItem $item): bool => $item->to_year_level_id !== null
                    && ! in_array($item->exception_code, self::BLOCKING_EXCEPTIONS, true))
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

                $sections = $this->sections->sectionsFor(
                    $run->targetTerm,
                    $programId,
                    $yearLevelId,
                    $majorId,
                    true,
                );

                $occupied = $sections->mapWithKeys(fn (Section $section): array => [
                    $section->id => $this->sections->occupiedSeats($section, $run->targetTerm),
                ])->all();

                $summary = $this->sections->summarizeGroup(
                    $programId,
                    $yearLevelId,
                    $majorId,
                    $group->count(),
                    $sections,
                    $occupied,
                );

                $remaining = $sections->mapWithKeys(fn (Section $section): array => [
                    $section->id => $section->capacity === null
                        ? null
                        : max(0, $section->capacity - ($occupied[$section->id] ?? 0)),
                ])->all();

                foreach ($group as $item) {
                    $assigned = $this->nextSection($sections, $remaining);

                    if ($assigned === null) {
                        $item->update([
                            'section_id' => null,
                            'status' => 'exception',
                            'exception_code' => 'section_shortage',
                            'exception_message' => 'No section has enough remaining capacity for this student.',
                        ]);

                        continue;
                    }

                    if ($remaining[$assigned->id] !== null) {
                        $remaining[$assigned->id]--;
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

    public function finalize(PromotionRun $run, ?User $actor, bool $allowShortages = false): PromotionRun
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

                $student = Student::query()->with('user')->lockForUpdate()->findOrFail($item->student_id);

                if ($student->status !== 'active' || $student->user?->status !== 'active') {
                    $item->update([
                        'status' => 'exception',
                        'exception_code' => 'student_inactive',
                        'exception_message' => 'The student account is no longer active.',
                    ]);

                    continue;
                }

                if (! $fromPlacement->is_current || $fromPlacement->status !== 'active') {
                    $item->update([
                        'status' => 'exception',
                        'exception_code' => 'source_placement_changed',
                        'exception_message' => 'The source placement is no longer the student current placement.',
                    ]);

                    continue;
                }

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

                $student->forceFill([
                    'current_placement_id' => $placement->id,
                    'section_id' => $placement->section_id,
                ])->save();

                $item->update(['status' => 'promoted', 'exception_code' => null, 'exception_message' => null]);
                $this->lifecycle->recordActivity(
                    $student,
                    $actor,
                    'placement_promoted',
                    'The student was promoted to a new academic placement.',
                    [
                        'placement_id' => $placement->id,
                        'promotion_run_id' => $run->id,
                        'academic_term_id' => $run->target_term_id,
                        'year_level_id' => $item->to_year_level_id,
                        'section_id' => $item->section_id,
                    ],
                );
                $promoted++;
            }

            $run->update([
                'status' => 'finalized',
                'finalized_by' => $actor?->id,
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

    /**
     * @return array<string, mixed>
     */
    private function processDueTerm(AcademicTerm $term, ?User $actor): array
    {
        $sourceYear = $this->academicYears->resolveForTerm($term);
        $base = [
            'academic_year_id' => $sourceYear->id,
            'academic_year' => $sourceYear->label,
            'source_term_id' => $term->id,
            'source_term' => $term->name,
            'ends_on' => $term->ends_on?->toDateString(),
        ];

        $existing = PromotionRun::query()
            ->with(['targetAcademicYear', 'targetTerm'])
            ->where('source_academic_year_id', $sourceYear->id)
            ->where('source_term_id', $term->id)
            ->first();

        if ($existing !== null && $existing->status === 'finalized') {
            return $this->processResult($base, $existing, 'finalized');
        }

        if ($existing !== null && $existing->prepared_by !== null) {
            return $this->processResult($base, $existing, 'manual_pending');
        }

        $targetYear = $existing?->targetAcademicYear;
        $targetTerm = $existing?->targetTerm;

        if ($targetYear === null || $targetTerm === null) {
            $targetYear = AcademicYear::query()
                ->where('id', '!=', $sourceYear->id)
                ->where('starts_on', '>', $sourceYear->starts_on)
                ->orderBy('starts_on')
                ->first();

            if ($targetYear === null) {
                return [
                    ...$base,
                    'status' => 'configuration_incomplete',
                    'promotion_run_id' => null,
                    'promoted_count' => 0,
                    'exception_count' => 0,
                    'message' => 'No later academic year is configured for promotion.',
                ];
            }

            $targetTerm = AcademicTerm::query()
                ->where('academic_year_id', $targetYear->id)
                ->where('term', 'first')
                ->orderBy('starts_on')
                ->first();
        }

        if (
            $targetTerm === null
            || $targetTerm->starts_on === null
            || $term->ends_on === null
            || $targetTerm->starts_on->lessThanOrEqualTo($term->ends_on)
        ) {
            return [
                ...$base,
                'status' => 'configuration_incomplete',
                'promotion_run_id' => null,
                'promoted_count' => 0,
                'exception_count' => 0,
                'message' => 'The target academic year needs a valid First Semester term.',
            ];
        }

        $outcome = DB::transaction(function () use ($sourceYear, $term, $targetYear, $targetTerm): array {
            $existing = PromotionRun::query()
                ->where('source_academic_year_id', $sourceYear->id)
                ->where('source_term_id', $term->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === 'finalized') {
                    return ['run' => $existing, 'state' => 'finalized'];
                }

                if ($existing->prepared_by !== null) {
                    return ['run' => $existing, 'state' => 'manual_pending'];
                }

                $run = $existing;

                if (
                    (int) $run->target_academic_year_id !== (int) $targetYear->id
                    || (int) $run->target_term_id !== (int) $targetTerm->id
                ) {
                    $run->update([
                        'target_academic_year_id' => $targetYear->id,
                        'target_term_id' => $targetTerm->id,
                    ]);
                }
            } else {
                $run = PromotionRun::create([
                    'source_academic_year_id' => $sourceYear->id,
                    'target_academic_year_id' => $targetYear->id,
                    'source_term_id' => $term->id,
                    'target_term_id' => $targetTerm->id,
                    'status' => 'prepared',
                    'prepared_by' => null,
                    'prepared_at' => now(),
                    'notes' => 'Automatically prepared after the Second Semester ended.',
                ]);
            }

            $placements = $this->sourcePlacements($sourceYear);

            if ($placements->isEmpty()) {
                $run->items()
                    ->where('status', '!=', 'promoted')
                    ->update([
                        'section_id' => null,
                        'status' => 'exception',
                        'exception_code' => 'not_eligible',
                        'exception_message' => 'The student is no longer in the eligible active population.',
                    ]);

                return ['run' => $run->refresh(), 'state' => 'no_eligible_students'];
            }

            $activeStudentIds = $placements->pluck('student_id')->all();

            $run->items()
                ->whereNotIn('student_id', $activeStudentIds)
                ->where('status', '!=', 'promoted')
                ->update([
                    'section_id' => null,
                    'status' => 'exception',
                    'exception_code' => 'not_eligible',
                    'exception_message' => 'The student is no longer in the eligible active population.',
                ]);

            foreach ($placements as $placement) {
                $this->prepareItem($run, $placement, $sourceYear, $targetYear, $targetTerm);
            }

            return ['run' => $run->refresh(), 'state' => 'prepared'];
        }, attempts: 3);

        $run = $outcome['run'];

        if (in_array($outcome['state'], ['finalized', 'manual_pending', 'no_eligible_students'], true)) {
            return $this->processResult($base, $run, $outcome['state']);
        }

        $this->allocate($run);
        $run = $run->refresh()->load('items');
        $exceptionCount = $run->items->where('status', 'exception')->count();

        if ($exceptionCount === 0) {
            $run = $this->finalize($run, $actor);
            $state = 'finalized';
        } else {
            $state = 'exceptions';
        }

        return $this->processResult($base, $run, $state);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function processResult(array $base, PromotionRun $run, string $state): array
    {
        $run->loadCount([
            'items as promoted_count' => fn ($query) => $query->where('status', 'promoted'),
            'items as exception_count' => fn ($query) => $query->where('status', 'exception'),
        ]);

        return [
            ...$base,
            'status' => $run->status,
            'promotion_run_id' => $run->id,
            'promoted_count' => (int) ($run->promoted_count ?? 0),
            'exception_count' => (int) ($run->exception_count ?? 0),
            'message' => match ($state) {
                'finalized' => 'Promotion completed.',
                'manual_pending' => 'A manually prepared promotion run is waiting for administrator action.',
                'no_eligible_students' => 'No active students are eligible for promotion.',
                'exceptions' => 'Promotion is waiting for unresolved exceptions.',
                default => null,
            },
        ];
    }

    private function prepareItem(
        PromotionRun $run,
        StudentAcademicPlacement $placement,
        AcademicYear $sourceYear,
        AcademicYear $targetYear,
        AcademicTerm $targetTerm,
    ): PromotionRunItem {
        $fromYearLevel = $placement->yearLevel;
        $toYearLevel = $fromYearLevel === null
            ? null
            : YearLevel::query()
                ->where('level', '>', $fromYearLevel->level)
                ->orderBy('level')
                ->first();

        $majorId = $placement->major_id;
        $exceptionCode = null;
        $exceptionMessage = null;

        if ($toYearLevel === null) {
            $exceptionCode = 'terminal_year_level';
            $exceptionMessage = 'No higher year level is configured for promotion.';
        } elseif (
            $majorId === null
            && $toYearLevel->level >= self::MAJOR_START_LEVEL
            && $this->programHasMajors((int) $placement->program_id)
        ) {
            $selection = StudentMajorSelection::query()
                ->where('student_id', $placement->student_id)
                ->whereIn('academic_year_id', [$targetYear->id, $sourceYear->id])
                ->whereIn('status', ['submitted', 'approved'])
                ->orderByRaw('CASE WHEN academic_year_id = ? THEN 0 ELSE 1 END', [$targetYear->id])
                ->latest('id')
                ->first();

            if ($selection === null) {
                $exceptionCode = 'major_required';
                $exceptionMessage = 'The student must select a major before promotion.';
            } else {
                $majorId = $selection->major_id;
            }
        }

        if (StudentAcademicPlacement::query()
            ->where('student_id', $placement->student_id)
            ->where('academic_term_id', $targetTerm->id)
            ->exists()) {
            $exceptionCode = 'already_placed';
            $exceptionMessage = 'The student already has a placement for the target term.';
        }

        return PromotionRunItem::updateOrCreate(
            [
                'promotion_run_id' => $run->id,
                'student_id' => $placement->student_id,
            ],
            [
                'from_placement_id' => $placement->id,
                'from_year_level_id' => $placement->year_level_id,
                'to_year_level_id' => $toYearLevel?->id,
                'major_id' => $majorId,
                'section_id' => null,
                'status' => $exceptionCode === null ? 'ready' : 'exception',
                'exception_code' => $exceptionCode,
                'exception_message' => $exceptionMessage,
            ],
        );
    }

    private function sourcePlacements(AcademicYear $sourceYear): Collection
    {
        return StudentAcademicPlacement::query()
            ->with(['student.user', 'program', 'yearLevel', 'major'])
            ->where('academic_year_id', $sourceYear->id)
            ->where('is_current', true)
            ->where('status', 'active')
            ->whereHas('student', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('user', fn ($userQuery) => $userQuery->where('status', 'active')))
            ->orderBy('student_id')
            ->get();
    }

    private function programHasMajors(int $programId): bool
    {
        return Major::query()
            ->where('program_id', $programId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @param  array<int, int|null>  $remaining
     */
    private function nextSection(Collection $sections, array $remaining): ?Section
    {
        return $sections
            ->filter(fn (Section $section): bool => $remaining[$section->id] === null || $remaining[$section->id] > 0)
            ->sort(function (Section $left, Section $right) use ($remaining): int {
                $leftRemaining = $remaining[$left->id];
                $rightRemaining = $remaining[$right->id];

                if ($leftRemaining === null && $rightRemaining === null) {
                    return [$left->code, $left->id] <=> [$right->code, $right->id];
                }

                if ($leftRemaining === null) {
                    return 1;
                }

                if ($rightRemaining === null) {
                    return -1;
                }

                $leftRatio = $left->capacity === null || $left->capacity === 0
                    ? 0.0
                    : $leftRemaining / $left->capacity;
                $rightRatio = $right->capacity === null || $right->capacity === 0
                    ? 0.0
                    : $rightRemaining / $right->capacity;

                if ($leftRatio === $rightRatio) {
                    return [$left->code, $left->id] <=> [$right->code, $right->id];
                }

                return $rightRatio <=> $leftRatio;
            })
            ->first();
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

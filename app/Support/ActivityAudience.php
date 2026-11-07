<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\FacultyAssignment;
use App\Models\Role;
use App\Models\Section;
use App\Models\StudentSubjectEnrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ActivityAudience
{
    /**
     * @return array{role_id: list<int>, program_id: list<int>, year_level_id: list<int>, section_id: list<int>, user_id: list<int>}
     */
    public static function normalizeTargets(array $targets = []): array
    {
        return [
            'role_id' => self::ids($targets['role_ids'] ?? []),
            'program_id' => self::ids($targets['program_ids'] ?? []),
            'year_level_id' => self::ids($targets['year_level_ids'] ?? []),
            'section_id' => self::ids($targets['section_ids'] ?? []),
            'user_id' => self::ids($targets['user_ids'] ?? []),
        ];
    }

    /**
     * @param  array{role_id: list<int>, program_id: list<int>, year_level_id: list<int>, section_id: list<int>, user_id: list<int>}  $targets
     */
    public static function assertValidTargets(User $actor, string $scope, array $targets): void
    {
        $hasTargets = collect($targets)->contains(fn (array $ids): bool => $ids !== []);

        if ($scope === 'all' && $hasTargets) {
            throw ValidationException::withMessages([
                'targets' => 'All-audience activities cannot include audience targets.',
            ]);
        }

        if ($scope === 'custom' && ! $hasTargets) {
            throw ValidationException::withMessages([
                'targets' => 'Select at least one audience target for a custom activity.',
            ]);
        }

        if ($actor->hasRole(Role::ADMINISTRATOR)) {
            return;
        }

        $assignedProgramIds = self::assignedProgramIds($actor);

        if ($scope !== 'custom' || $targets['program_id'] === []) {
            throw ValidationException::withMessages([
                'targets.program_ids' => 'Department Personnel must target at least one assigned program.',
            ]);
        }

        if (array_diff($targets['program_id'], $assignedProgramIds) !== []) {
            throw ValidationException::withMessages([
                'targets.program_ids' => 'Every program target must be within your assigned program access.',
            ]);
        }

        if ($targets['section_id'] !== []) {
            $validSectionIds = Section::query()
                ->whereIn('id', $targets['section_id'])
                ->whereIn('program_id', $assignedProgramIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (array_diff($targets['section_id'], $validSectionIds) !== []) {
                throw ValidationException::withMessages([
                    'targets.section_ids' => 'Every section target must belong to one of your assigned programs.',
                ]);
            }
        }

        if ($targets['user_id'] !== []) {
            $users = User::query()
                ->with([
                    'student:id,user_id,program_id',
                    'professor:id,user_id,program_id',
                ])
                ->whereIn('id', $targets['user_id'])
                ->get();

            foreach ($users as $targetUser) {
                $programId = $targetUser->student?->program_id ?? $targetUser->professor?->program_id;

                if ($programId === null || ! in_array((int) $programId, $assignedProgramIds, true)) {
                    throw ValidationException::withMessages([
                        'targets.user_ids' => 'Every individual target must belong to one of your assigned programs.',
                    ]);
                }
            }
        }
    }

    public static function applyVisibility(Builder $query, User $actor): Builder
    {
        $query->where(function (Builder $audience): void {
            $audience->where('activities.audience_scope', 'all')
                ->orWhereHas('targets');
        });

        if ($actor->hasRole(Role::ADMINISTRATOR)) {
            return $query;
        }

        $programIds = self::programIds($actor);
        $sectionIds = self::sectionIds($actor, $programIds);
        $yearLevelIds = self::yearLevelIds($sectionIds);

        self::applyDimension($query, 'role_id', $actor->role_id === null ? [] : [(int) $actor->role_id]);
        self::applyDimension($query, 'program_id', $programIds);
        self::applyDimension($query, 'year_level_id', $yearLevelIds);
        self::applyDimension($query, 'section_id', $sectionIds);
        self::applyDimension($query, 'user_id', [(int) $actor->id]);

        return $query;
    }

    public static function isVisible(Activity $activity, User $actor): bool
    {
        if (! $actor->isActive() || ! in_array($activity->status, ['published', 'cancelled', 'completed'], true)) {
            return false;
        }

        $query = Activity::query()
            ->whereKey($activity->id)
            ->whereIn('status', ['published', 'cancelled', 'completed']);

        return self::applyVisibility($query, $actor)->exists();
    }

    /**
     * @return list<int>
     */
    public static function assignedProgramIds(User $actor): array
    {
        return $actor->departmentPersonnelPrograms()
            ->pluck('programs.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $values
     */
    private static function applyDimension(Builder $query, string $column, array $values): void
    {
        $query->where(function (Builder $dimension) use ($column, $values): void {
            $dimension->whereDoesntHave(
                'targets',
                fn (Builder $target): Builder => $target->whereNotNull('activity_targets.'.$column),
            );

            if ($values !== []) {
                $dimension->orWhereHas(
                    'targets',
                    fn (Builder $target): Builder => $target->whereIn('activity_targets.'.$column, $values),
                );
            }
        });
    }

    /**
     * @return list<int>
     */
    private static function programIds(User $actor): array
    {
        if ($actor->hasRole(Role::DEPARTMENT_PERSONNEL)) {
            return self::assignedProgramIds($actor);
        }

        if ($actor->hasRole(Role::STUDENT)) {
            return $actor->student?->program_id === null ? [] : [(int) $actor->student->program_id];
        }

        if ($actor->hasRole(Role::PROFESSOR)) {
            return $actor->professor?->program_id === null ? [] : [(int) $actor->professor->program_id];
        }

        return [];
    }

    /**
     * @param  list<int>  $programIds
     * @return list<int>
     */
    private static function sectionIds(User $actor, array $programIds): array
    {
        if ($actor->hasRole(Role::DEPARTMENT_PERSONNEL)) {
            return Section::query()
                ->whereIn('program_id', $programIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        if ($actor->hasRole(Role::STUDENT) && $actor->student !== null) {
            return StudentSubjectEnrollment::query()
                ->where('student_id', $actor->student->id)
                ->where('status', 'enrolled')
                ->with('subjectOffering:id,section_id')
                ->get()
                ->pluck('subjectOffering.section_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        if ($actor->hasRole(Role::PROFESSOR) && $actor->professor !== null) {
            return FacultyAssignment::query()
                ->where('professor_id', $actor->professor->id)
                ->where('status', 'active')
                ->with('subjectOffering:id,section_id')
                ->get()
                ->pluck('subjectOffering.section_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  list<int>  $sectionIds
     * @return list<int>
     */
    private static function yearLevelIds(array $sectionIds): array
    {
        if ($sectionIds === []) {
            return [];
        }

        return Section::query()
            ->whereIn('id', $sectionIds)
            ->pluck('year_level_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\ActivityHistory;
use App\Models\ActivityTarget;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityAudience;
use App\Support\ActivityPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivityManagementService
{
    public function managePage(array $filters, User $actor): array
    {
        $now = CarbonImmutable::now();
        $query = Activity::query()->with($this->relations());
        $this->applyManagerScope($query, $actor);
        $overview = $this->overview(clone $query, $now);
        $this->applyFilters($query, $filters);

        $paginator = $query
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'activities' => collect($paginator->items())
                ->map(fn (Activity $activity): array => ActivityPresenter::activity($activity, true))
                ->all(),
            'overview' => $overview,
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function feedPage(array $filters, User $actor): array
    {
        $query = Activity::query()
            ->with($this->relations())
            ->whereIn('status', ['published', 'cancelled', 'completed']);

        ActivityAudience::applyVisibility($query, $actor);
        $this->applyFilters($query, $filters, includeStatus: false);
        $this->applyFeedStatus($query, $filters['status'] ?? null, CarbonImmutable::now());

        $paginator = $query
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'activities' => collect($paginator->items())
                ->map(fn (Activity $activity): array => ActivityPresenter::activity($activity))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function historyPage(Activity $activity, array $filters): array
    {
        $paginator = ActivityHistory::query()
            ->with('actedBy:id,name')
            ->where('activity_id', $activity->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'history' => collect($paginator->items())
                ->map(fn (ActivityHistory $history): array => ActivityPresenter::history($history))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function create(array $attributes, User $actor): Activity
    {
        $scope = (string) $attributes['audience_scope'];
        $targets = ActivityAudience::normalizeTargets($attributes['targets'] ?? []);
        unset($attributes['targets']);
        ActivityAudience::assertValidTargets($actor, $scope, $targets);

        return DB::transaction(function () use ($attributes, $actor, $scope, $targets): Activity {
            $activity = Activity::create([
                ...$attributes,
                'created_by_user_id' => $actor->id,
                'audience_scope' => $scope,
                'status' => 'draft',
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
                'cancellation_reason' => null,
                'completed_at' => null,
                'completed_by_user_id' => null,
            ]);

            $this->syncTargets($activity, $targets);
            $activity->refresh();
            $this->recordHistory($activity, 'created', null, 'draft', null, $this->snapshot($activity), $actor);

            return $this->fresh($activity);
        }, attempts: 3);
    }

    public function update(Activity $activity, array $attributes, User $actor): Activity
    {
        return DB::transaction(function () use ($activity, $attributes, $actor): Activity {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->id);

            if (in_array($locked->status, ['cancelled', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Cancelled or completed activities are final and cannot be changed.',
                ]);
            }

            $previous = $this->snapshot($locked);
            $scope = array_key_exists('audience_scope', $attributes)
                ? (string) $attributes['audience_scope']
                : $locked->audience_scope;
            $targetsProvided = array_key_exists('targets', $attributes);
            $targets = $targetsProvided
                ? ActivityAudience::normalizeTargets($attributes['targets'] ?? [])
                : $this->existingTargets($locked);
            unset($attributes['targets']);

            if ($scope === 'all' && ! $targetsProvided) {
                $targets = ActivityAudience::normalizeTargets();
            }
            ActivityAudience::assertValidTargets($actor, $scope, $targets);

            $locked->fill([
                ...$attributes,
                'audience_scope' => $scope,
            ]);
            $locked->save();
            if ($targetsProvided || $scope === 'all') {
                $this->syncTargets($locked, $targets);
            }

            $locked->refresh();
            $this->recordHistory(
                $locked,
                'updated',
                $previous['status'],
                $locked->status,
                $previous,
                $this->snapshot($locked),
                $actor,
            );

            return $this->fresh($locked);
        }, attempts: 3);
    }

    public function publish(Activity $activity, User $actor): Activity
    {
        return DB::transaction(function () use ($activity, $actor): Activity {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft activities can be published.',
                ]);
            }

            $previous = $this->snapshot($locked);
            $locked->update(['status' => 'published']);
            $locked->refresh();
            $this->recordHistory($locked, 'published', 'draft', 'published', $previous, $this->snapshot($locked), $actor);

            return $this->fresh($locked);
        }, attempts: 3);
    }

    public function cancel(Activity $activity, string $reason, User $actor): Activity
    {
        return DB::transaction(function () use ($activity, $reason, $actor): Activity {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            if ($locked->status !== 'published') {
                throw ValidationException::withMessages([
                    'status' => 'Only published activities can be cancelled.',
                ]);
            }

            $previous = $this->snapshot($locked);
            $locked->update([
                'status' => 'cancelled',
                'cancellation_reason' => trim($reason),
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by_user_id' => $actor->id,
            ]);
            $locked->refresh();
            $this->recordHistory(
                $locked,
                'cancelled',
                'published',
                'cancelled',
                $previous,
                $this->snapshot($locked),
                $actor,
            );

            return $this->fresh($locked);
        }, attempts: 3);
    }

    public function complete(Activity $activity, User $actor): Activity
    {
        return DB::transaction(function () use ($activity, $actor): Activity {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            if ($locked->status !== 'published') {
                throw ValidationException::withMessages([
                    'status' => 'Only published activities can be marked completed.',
                ]);
            }

            if ($locked->ends_at->isAfter(CarbonImmutable::now())) {
                throw ValidationException::withMessages([
                    'status' => 'An activity can be marked completed only after its scheduled end time.',
                ]);
            }

            $previous = $this->snapshot($locked);
            $locked->update([
                'status' => 'completed',
                'completed_at' => CarbonImmutable::now(),
                'completed_by_user_id' => $actor->id,
            ]);
            $locked->refresh();
            $this->recordHistory(
                $locked,
                'completed',
                'published',
                'completed',
                $previous,
                $this->snapshot($locked),
                $actor,
            );

            return $this->fresh($locked);
        }, attempts: 3);
    }

    /**
     * @return array<string, string>
     */
    private function relations(): array
    {
        return [
            'createdBy:id,name',
            'room:id,code,name,building,floor',
            'cancelledBy:id,name',
            'completedBy:id,name',
            'targets.role:id,code,name',
            'targets.program:id,code,name',
            'targets.yearLevel:id,level,name',
            'targets.section:id,code,name,program_id,year_level_id',
            'targets.user:id,name',
        ];
    }

    private function fresh(Activity $activity): Activity
    {
        return $activity->fresh($this->relations());
    }

    private function applyManagerScope(Builder $query, User $actor): void
    {
        if (! $actor->hasRole(Role::DEPARTMENT_PERSONNEL)) {
            return;
        }

        $programIds = ActivityAudience::assignedProgramIds($actor);
        $query->where('audience_scope', 'custom')
            ->whereHas(
                'targets',
                fn (Builder $target): Builder => $target->whereIn('program_id', $programIds),
            )
            ->whereDoesntHave(
                'targets',
                fn (Builder $target): Builder => $target
                    ->whereNotNull('program_id')
                    ->whereNotIn('program_id', $programIds),
            );
    }

    private function applyFilters(Builder $query, array $filters, bool $includeStatus = true): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('title', 'like', '%'.$search.'%')
                    ->orWhere('summary', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('organizer_name', 'like', '%'.$search.'%');
            });
        }

        $fields = $includeStatus
            ? ['category', 'status', 'audience_scope']
            : ['category', 'audience_scope'];
        foreach ($fields as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['organizer'])) {
            $query->where('organizer_name', 'like', '%'.trim($filters['organizer']).'%');
        }

        $timezone = 'Asia/Manila';
        if (! empty($filters['from_date'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from_date'], $timezone)->utc();
            $query->where('starts_at', '>=', $from);
        }
        if (! empty($filters['to_date'])) {
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to_date'], $timezone)
                ->endOfDay()
                ->utc();
            $query->where('starts_at', '<=', $to);
        }
    }

    private function applyFeedStatus(Builder $query, ?string $status, CarbonImmutable $now): void
    {
        if ($status === null || $status === '') {
            return;
        }

        match ($status) {
            'upcoming' => $query->where('status', 'published')->where('starts_at', '>', $now),
            'ongoing' => $query->where('status', 'published')
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now),
            'completed' => $query->where(function (Builder $completed) use ($now): void {
                $completed->where('status', 'completed')
                    ->orWhere(fn (Builder $published) => $published
                        ->where('status', 'published')
                        ->where('ends_at', '<=', $now));
            }),
            'cancelled' => $query->where('status', 'cancelled'),
        };
    }

    /**
     * @return array{total: int, upcoming: int, ongoing: int, completed: int, cancelled: int, draft: int}
     */
    private function overview(Builder $query, CarbonImmutable $now): array
    {
        return [
            'total' => (clone $query)->count(),
            'upcoming' => (clone $query)->where('status', 'published')->where('starts_at', '>', $now)->count(),
            'ongoing' => (clone $query)
                ->where('status', 'published')
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now)
                ->count(),
            'completed' => (clone $query)->where(function (Builder $completed) use ($now): void {
                $completed->where('status', 'completed')
                    ->orWhere(fn (Builder $published) => $published
                        ->where('status', 'published')
                        ->where('ends_at', '<=', $now));
            })->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
            'draft' => (clone $query)->where('status', 'draft')->count(),
        ];
    }

    /**
     * @param  array{role_id: list<int>, program_id: list<int>, year_level_id: list<int>, section_id: list<int>, user_id: list<int>}  $targets
     */
    private function syncTargets(Activity $activity, array $targets): void
    {
        $activity->targets()->delete();

        foreach ($targets as $column => $ids) {
            foreach ($ids as $id) {
                ActivityTarget::create([
                    'activity_id' => $activity->id,
                    $column => $id,
                ]);
            }
        }
    }

    /**
     * @return array{role_id: list<int>, program_id: list<int>, year_level_id: list<int>, section_id: list<int>, user_id: list<int>}
     */
    private function existingTargets(Activity $activity): array
    {
        $activity->loadMissing('targets');

        return [
            'role_id' => $activity->targets->whereNotNull('role_id')->pluck('role_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'program_id' => $activity->targets->whereNotNull('program_id')->pluck('program_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'year_level_id' => $activity->targets->whereNotNull('year_level_id')->pluck('year_level_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'section_id' => $activity->targets->whereNotNull('section_id')->pluck('section_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'user_id' => $activity->targets->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    private function snapshot(Activity $activity): array
    {
        return [
            'title' => $activity->title,
            'summary' => $activity->summary,
            'description' => $activity->description,
            'category' => $activity->category,
            'organizer_name' => $activity->organizer_name,
            'room_id' => $activity->room_id,
            'venue_name' => $activity->venue_name,
            'location_details' => $activity->location_details,
            'audience_scope' => $activity->audience_scope,
            'starts_at' => $activity->starts_at?->utc()->toIso8601String(),
            'ends_at' => $activity->ends_at?->utc()->toIso8601String(),
            'status' => $activity->status,
            'cancellation_reason' => $activity->cancellation_reason,
            'cancelled_at' => $activity->cancelled_at?->utc()->toIso8601String(),
            'completed_at' => $activity->completed_at?->utc()->toIso8601String(),
            'targets' => $this->existingTargets($activity),
        ];
    }

    private function recordHistory(
        Activity $activity,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        ?array $previousValues,
        ?array $newValues,
        User $actor,
    ): void {
        ActivityHistory::create([
            'activity_id' => $activity->id,
            'acted_by_user_id' => $actor->id,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'previous_values' => $previousValues,
            'new_values' => $newValues,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function pagination(mixed $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}

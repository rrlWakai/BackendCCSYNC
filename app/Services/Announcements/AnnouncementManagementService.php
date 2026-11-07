<?php

namespace App\Services\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementHistory;
use App\Models\AnnouncementTarget;
use App\Models\Role;
use App\Models\User;
use App\Support\AnnouncementAudience;
use App\Support\AnnouncementPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AnnouncementManagementService
{
    public function managePage(array $filters, User $actor): array
    {
        $now = CarbonImmutable::now();
        $query = Announcement::query()->with($this->relations());

        if ($actor->hasRole(Role::DEPARTMENT_PERSONNEL)) {
            $programIds = $actor->departmentPersonnelPrograms()
                ->pluck('programs.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $query->whereHas(
                'targets',
                fn (Builder $target): Builder => $target->whereIn('program_id', $programIds),
            )->whereDoesntHave(
                'targets',
                fn (Builder $target): Builder => $target
                    ->whereNotNull('program_id')
                    ->whereNotIn('program_id', $programIds),
            );
        }

        $overview = $this->overview(clone $query, $now);
        $this->applyListingFilters($query, $filters);

        $paginator = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'announcements' => collect($paginator->items())
                ->map(fn (Announcement $announcement): array => AnnouncementPresenter::announcement($announcement))
                ->all(),
            'overview' => $overview,
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function feedPage(array $filters, User $actor): array
    {
        $now = CarbonImmutable::now();
        $query = Announcement::query()
            ->with($this->relations())
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->where(function (Builder $expiry) use ($now): void {
                $expiry->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });

        AnnouncementAudience::applyFeedVisibility($query, $actor);
        $this->applyListingFilters($query, $filters);

        $paginator = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'announcements' => collect($paginator->items())
                ->map(fn (Announcement $announcement): array => AnnouncementPresenter::announcement($announcement))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function historyPage(Announcement $announcement, array $filters): array
    {
        $paginator = AnnouncementHistory::query()
            ->with('actedBy:id,name')
            ->where('announcement_id', $announcement->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'history' => collect($paginator->items())
                ->map(fn (AnnouncementHistory $history): array => AnnouncementPresenter::history($history))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function create(array $attributes, User $actor): Announcement
    {
        $scope = (string) ($attributes['audience_scope'] ?? 'all');
        $targets = AnnouncementAudience::normalizeTargets($attributes['targets'] ?? []);
        unset($attributes['targets']);

        AnnouncementAudience::assertValidTargets($actor, $scope, $targets);

        return DB::transaction(function () use ($attributes, $actor, $scope, $targets): Announcement {
            $announcement = Announcement::create([
                ...$attributes,
                'author_user_id' => $actor->id,
                'audience_scope' => $scope,
                'status' => 'draft',
                'archived_at' => null,
            ]);

            $this->syncTargets($announcement, $targets);
            $announcement->refresh();
            $this->recordHistory(
                $announcement,
                'created',
                null,
                'draft',
                null,
                $this->snapshot($announcement),
                $actor,
            );

            return $this->fresh($announcement);
        }, attempts: 3);
    }

    public function update(Announcement $announcement, array $attributes, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $attributes, $actor): Announcement {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);

            if ($locked->status === 'archived') {
                throw ValidationException::withMessages([
                    'status' => 'Archived announcements are final and cannot be changed.',
                ]);
            }

            $previous = $this->snapshot($locked);
            $scope = array_key_exists('audience_scope', $attributes)
                ? (string) $attributes['audience_scope']
                : $locked->audience_scope;
            $targetsProvided = array_key_exists('targets', $attributes);
            $targets = $targetsProvided
                ? AnnouncementAudience::normalizeTargets($attributes['targets'] ?? [])
                : $this->existingTargets($locked);

            unset($attributes['targets']);

            if ($scope === 'all' && ! $targetsProvided) {
                $targets = AnnouncementAudience::normalizeTargets();
            }

            AnnouncementAudience::assertValidTargets($actor, $scope, $targets);

            $nextPublishedAt = array_key_exists('published_at', $attributes)
                ? $attributes['published_at']
                : $locked->published_at;
            $nextExpiresAt = array_key_exists('expires_at', $attributes)
                ? $attributes['expires_at']
                : $locked->expires_at;

            if ($locked->status === 'published' && $nextPublishedAt === null) {
                throw ValidationException::withMessages([
                    'published_at' => 'A published announcement must keep a publication date.',
                ]);
            }

            $this->assertExpiry($nextPublishedAt, $nextExpiresAt);

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

    public function publish(Announcement $announcement, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $actor): Announcement {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft announcements can be published.',
                ]);
            }

            $previous = $this->snapshot($locked);
            $publishedAt = $locked->published_at ?? CarbonImmutable::now();
            $this->assertExpiry($publishedAt, $locked->expires_at);

            $locked->update([
                'status' => 'published',
                'published_at' => $publishedAt,
            ]);
            $locked->refresh();

            $this->recordHistory(
                $locked,
                'published',
                'draft',
                'published',
                $previous,
                $this->snapshot($locked),
                $actor,
            );

            return $this->fresh($locked);
        }, attempts: 3);
    }

    public function archive(Announcement $announcement, User $actor): Announcement
    {
        return DB::transaction(function () use ($announcement, $actor): Announcement {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);

            if ($locked->status === 'archived') {
                throw ValidationException::withMessages([
                    'status' => 'This announcement is already archived.',
                ]);
            }

            $previous = $this->snapshot($locked);
            $previousStatus = $locked->status;

            $locked->update([
                'status' => 'archived',
                'archived_at' => CarbonImmutable::now(),
            ]);
            $locked->refresh();

            $this->recordHistory(
                $locked,
                'archived',
                $previousStatus,
                'archived',
                $previous,
                $this->snapshot($locked),
                $actor,
            );

            return $this->fresh($locked);
        }, attempts: 3);
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return [
            'author:id,name',
            'targets.role:id,code,name',
            'targets.program:id,code,name',
            'targets.yearLevel:id,level,name',
            'targets.section:id,code,name,program_id,year_level_id',
            'targets.user:id,name',
        ];
    }

    private function fresh(Announcement $announcement): Announcement
    {
        return $announcement->fresh($this->relations());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyListingFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('title', 'like', '%'.$search.'%')
                    ->orWhere('summary', 'like', '%'.$search.'%')
                    ->orWhere('content', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
    }

    /**
     * @return array{total: int, draft: int, scheduled: int, published: int, expired: int, archived: int}
     */
    private function overview(Builder $query, CarbonImmutable $now): array
    {
        return [
            'total' => (clone $query)->count(),
            'draft' => (clone $query)->where('status', 'draft')->count(),
            'scheduled' => (clone $query)
                ->where('status', 'published')
                ->where('published_at', '>', $now)
                ->count(),
            'published' => (clone $query)
                ->where('status', 'published')
                ->where('published_at', '<=', $now)
                ->where(function (Builder $expiry) use ($now): void {
                    $expiry->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->count(),
            'expired' => (clone $query)
                ->where('status', 'published')
                ->where('published_at', '<=', $now)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->count(),
            'archived' => (clone $query)->where('status', 'archived')->count(),
        ];
    }

    /**
     * @param  array{role_id: list<int>, program_id: list<int>, year_level_id: list<int>, section_id: list<int>, user_id: list<int>}  $targets
     */
    private function syncTargets(Announcement $announcement, array $targets): void
    {
        $announcement->targets()->delete();

        foreach ($targets as $column => $ids) {
            foreach ($ids as $id) {
                AnnouncementTarget::create([
                    'announcement_id' => $announcement->id,
                    $column => $id,
                ]);
            }
        }
    }

    /**
     * @return array{role_id: list<int>, program_id: list<int>, year_level_id: list<int>, section_id: list<int>, user_id: list<int>}
     */
    private function existingTargets(Announcement $announcement): array
    {
        $announcement->loadMissing('targets');

        return [
            'role_id' => $announcement->targets->whereNotNull('role_id')->pluck('role_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'program_id' => $announcement->targets->whereNotNull('program_id')->pluck('program_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'year_level_id' => $announcement->targets->whereNotNull('year_level_id')->pluck('year_level_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'section_id' => $announcement->targets->whereNotNull('section_id')->pluck('section_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'user_id' => $announcement->targets->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param  array{role_id: list<int>, program_id: list<int>, year_level_id: list<int>, section_id: list<int>, user_id: list<int>}  $targets
     */
    private function snapshot(Announcement $announcement): array
    {
        return [
            'title' => $announcement->title,
            'summary' => $announcement->summary,
            'content' => $announcement->content,
            'category' => $announcement->category,
            'priority' => $announcement->priority,
            'audience_scope' => $announcement->audience_scope,
            'status' => $announcement->status,
            'published_at' => $announcement->published_at?->toIso8601String(),
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'archived_at' => $announcement->archived_at?->toIso8601String(),
            'targets' => $this->existingTargets($announcement),
        ];
    }

    private function recordHistory(
        Announcement $announcement,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        ?array $previousValues,
        ?array $newValues,
        User $actor,
    ): void {
        AnnouncementHistory::create([
            'announcement_id' => $announcement->id,
            'acted_by_user_id' => $actor->id,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'previous_values' => $previousValues,
            'new_values' => $newValues,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function assertExpiry(mixed $publishedAt, mixed $expiresAt): void
    {
        if ($publishedAt === null || $expiresAt === null) {
            return;
        }

        if (! CarbonImmutable::parse($expiresAt)->isAfter(CarbonImmutable::parse($publishedAt))) {
            throw ValidationException::withMessages([
                'expires_at' => 'Expiration date and time must be later than the scheduled publication date and time.',
            ]);
        }
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

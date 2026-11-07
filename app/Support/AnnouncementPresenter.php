<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\AnnouncementHistory;
use Carbon\CarbonImmutable;

final class AnnouncementPresenter
{
    private static function toApiTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->utc()->toIso8601String();
    }

    public static function announcement(Announcement $announcement): array
    {
        $announcement->loadMissing([
            'author:id,name',
            'targets.role:id,code,name',
            'targets.program:id,code,name',
            'targets.yearLevel:id,level,name',
            'targets.section:id,code,name,program_id,year_level_id',
            'targets.user:id,name',
        ]);

        $targets = $announcement->targets;

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'summary' => $announcement->summary,
            'content' => $announcement->content,
            'category' => $announcement->category,
            'priority' => $announcement->priority,
            'audience_scope' => $announcement->audience_scope,
            'status' => $announcement->status,
            'publication_state' => self::publicationState($announcement),
            'published_at' => self::toApiTime($announcement->published_at),
            'expires_at' => self::toApiTime($announcement->expires_at),
            'archived_at' => self::toApiTime($announcement->archived_at),
            'created_at' => self::toApiTime($announcement->created_at),
            'updated_at' => self::toApiTime($announcement->updated_at),
            'author' => [
                'id' => $announcement->author->id,
                'name' => $announcement->author->name,
            ],
            'audience_summary' => self::audienceSummary($announcement),
            'targets' => [
                'roles' => $targets
                    ->filter(fn ($target): bool => $target->role !== null)
                    ->map(fn ($target): array => [
                        'id' => $target->role->id,
                        'code' => $target->role->code,
                        'name' => $target->role->name,
                    ])
                    ->unique('id')
                    ->values()
                    ->all(),
                'programs' => $targets
                    ->filter(fn ($target): bool => $target->program !== null)
                    ->map(fn ($target): array => [
                        'id' => $target->program->id,
                        'code' => $target->program->code,
                        'name' => $target->program->name,
                    ])
                    ->unique('id')
                    ->values()
                    ->all(),
                'year_levels' => $targets
                    ->filter(fn ($target): bool => $target->yearLevel !== null)
                    ->map(fn ($target): array => [
                        'id' => $target->yearLevel->id,
                        'level' => $target->yearLevel->level,
                        'name' => $target->yearLevel->name,
                    ])
                    ->unique('id')
                    ->values()
                    ->all(),
                'sections' => $targets
                    ->filter(fn ($target): bool => $target->section !== null)
                    ->map(fn ($target): array => [
                        'id' => $target->section->id,
                        'code' => $target->section->code,
                        'name' => $target->section->name,
                        'program_id' => $target->section->program_id,
                        'year_level_id' => $target->section->year_level_id,
                    ])
                    ->unique('id')
                    ->values()
                    ->all(),
                'users' => $targets
                    ->filter(fn ($target): bool => $target->user !== null)
                    ->map(fn ($target): array => [
                        'id' => $target->user->id,
                        'name' => $target->user->name,
                    ])
                    ->unique('id')
                    ->values()
                    ->all(),
            ],
        ];
    }

    public static function history(AnnouncementHistory $history): array
    {
        $history->loadMissing('actedBy:id,name');

        return [
            'id' => $history->id,
            'action' => $history->action,
            'previous_status' => $history->previous_status,
            'new_status' => $history->new_status,
            'previous_values' => $history->previous_values,
            'new_values' => $history->new_values,
            'acted_by' => $history->actedBy === null ? null : [
                'id' => $history->actedBy->id,
                'name' => $history->actedBy->name,
            ],
            'created_at' => self::toApiTime($history->created_at),
        ];
    }

    public static function publicationState(Announcement $announcement): string
    {
        if ($announcement->status === 'draft') {
            return 'draft';
        }

        if ($announcement->status === 'archived') {
            return 'archived';
        }

        $now = CarbonImmutable::now();

        if ($announcement->published_at !== null && $announcement->published_at->isAfter($now)) {
            return 'scheduled';
        }

        if ($announcement->expires_at !== null && ! $announcement->expires_at->isAfter($now)) {
            return 'expired';
        }

        return 'published';
    }

    private static function audienceSummary(Announcement $announcement): string
    {
        if ($announcement->audience_scope === 'all') {
            return 'All active users';
        }

        $targets = $announcement->targets;
        $parts = [];

        $roleNames = $targets
            ->filter(fn ($target): bool => $target->role !== null)
            ->map(fn ($target): string => $target->role->name)
            ->unique()
            ->values()
            ->all();

        if ($roleNames !== []) {
            $parts[] = implode(', ', $roleNames);
        }

        $programNames = $targets
            ->filter(fn ($target): bool => $target->program !== null)
            ->map(fn ($target): string => $target->program->name)
            ->unique()
            ->values()
            ->all();

        if ($programNames !== []) {
            $parts[] = implode(', ', $programNames);
        }

        $yearNames = $targets
            ->filter(fn ($target): bool => $target->yearLevel !== null)
            ->map(fn ($target): string => $target->yearLevel->name)
            ->unique()
            ->values()
            ->all();

        if ($yearNames !== []) {
            $parts[] = implode(', ', $yearNames);
        }

        $sectionCodes = $targets
            ->filter(fn ($target): bool => $target->section !== null)
            ->map(fn ($target): string => $target->section->code)
            ->unique()
            ->values()
            ->all();

        if ($sectionCodes !== []) {
            $parts[] = implode(', ', $sectionCodes);
        }

        $userCount = $targets
            ->filter(fn ($target): bool => $target->user !== null)
            ->pluck('user_id')
            ->unique()
            ->count();

        if ($userCount > 0) {
            $parts[] = $userCount.' selected user'.($userCount === 1 ? '' : 's');
        }

        return $parts === [] ? 'Custom audience' : implode(' · ', $parts);
    }
}

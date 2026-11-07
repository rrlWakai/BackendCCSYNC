<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\ActivityHistory;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class ActivityPresenter
{
    public static function activity(Activity $activity, bool $includeTargets = false): array
    {
        $activity->loadMissing([
            'createdBy:id,name',
            'room:id,code,name,building,floor',
            'cancelledBy:id,name',
            'completedBy:id,name',
            'targets.role:id,code,name',
            'targets.program:id,code,name',
            'targets.yearLevel:id,level,name',
            'targets.section:id,code,name,program_id,year_level_id',
            'targets.user:id,name',
        ]);

        $data = [
            'id' => $activity->id,
            'title' => $activity->title,
            'summary' => $activity->summary,
            'description' => $activity->description,
            'category' => $activity->category,
            'organizer_name' => $activity->organizer_name,
            'venue_name' => $activity->venue_name,
            'location_details' => $activity->location_details,
            'room' => $activity->room === null ? null : [
                'id' => $activity->room->id,
                'code' => $activity->room->code,
                'name' => $activity->room->name,
                'building' => $activity->room->building,
                'floor' => $activity->room->floor,
            ],
            'audience_scope' => $activity->audience_scope,
            'audience_summary' => $activity->audience_scope === 'all'
                ? 'All active users'
                : ($includeTargets ? self::audienceSummary($activity) : 'For selected audiences'),
            'starts_at' => self::utc($activity->starts_at),
            'ends_at' => self::utc($activity->ends_at),
            'status' => $activity->status,
            'activity_state' => self::activityState($activity),
            'cancellation_reason' => $activity->cancellation_reason,
            'cancelled_at' => self::utc($activity->cancelled_at),
            'cancelled_by' => $activity->cancelledBy === null ? null : [
                'id' => $activity->cancelledBy->id,
                'name' => $activity->cancelledBy->name,
            ],
            'completed_at' => self::utc($activity->completed_at),
            'created_by' => $activity->createdBy === null ? null : [
                'id' => $activity->createdBy->id,
                'name' => $activity->createdBy->name,
            ],
            'created_at' => self::utc($activity->created_at),
            'updated_at' => self::utc($activity->updated_at),
        ];

        if ($includeTargets) {
            $data['targets'] = [
                'role_ids' => $activity->targets->whereNotNull('role_id')->pluck('role_id')->map(fn ($id): int => (int) $id)->values()->all(),
                'program_ids' => $activity->targets->whereNotNull('program_id')->pluck('program_id')->map(fn ($id): int => (int) $id)->values()->all(),
                'year_level_ids' => $activity->targets->whereNotNull('year_level_id')->pluck('year_level_id')->map(fn ($id): int => (int) $id)->values()->all(),
                'section_ids' => $activity->targets->whereNotNull('section_id')->pluck('section_id')->map(fn ($id): int => (int) $id)->values()->all(),
                'user_ids' => $activity->targets->whereNotNull('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all(),
            ];
        }

        return $data;
    }

    public static function history(ActivityHistory $history): array
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
            'created_at' => self::utc($history->created_at),
        ];
    }

    public static function activityState(Activity $activity): string
    {
        if ($activity->status !== 'published') {
            return $activity->status;
        }

        $now = CarbonImmutable::now();
        if ($activity->ends_at->lessThanOrEqualTo($now)) {
            return 'completed';
        }

        return $activity->starts_at->greaterThan($now) ? 'upcoming' : 'ongoing';
    }

    private static function utc(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : CarbonImmutable::instance($date)->utc()->toIso8601String();
    }

    private static function audienceSummary(Activity $activity): string
    {
        $targets = $activity->targets;
        $parts = [];

        foreach ([
            ['role', 'role_id'],
            ['program', 'program_id'],
            ['yearLevel', 'year_level_id'],
            ['section', 'section_id'],
        ] as [$relation, $column]) {
            $names = $targets
                ->filter(fn ($target): bool => $target->{$relation} !== null)
                ->map(fn ($target): string => $target->{$relation}->name
                    ?? $target->{$relation}->code
                    ?? 'Year '.$target->{$relation}->level)
                ->unique()
                ->values()
                ->all();

            if ($names !== []) {
                $parts[] = implode(', ', $names);
            }
        }

        $userCount = $targets->whereNotNull('user_id')->count();
        if ($userCount > 0) {
            $parts[] = $userCount.' selected user'.($userCount === 1 ? '' : 's');
        }

        return $parts === [] ? 'Custom audience' : implode(' · ', $parts);
    }
}

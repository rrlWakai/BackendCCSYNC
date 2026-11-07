<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Support\ActivityAudience;

final class ActivityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isActive()
            && $actor->hasRole(
                Role::ADMINISTRATOR,
                Role::DEPARTMENT_PERSONNEL,
                Role::PROFESSOR,
                Role::STUDENT,
            );
    }

    public function view(User $actor, Activity $activity): bool
    {
        return ActivityAudience::isVisible($activity, $actor)
            || $this->canManageActivity($actor, $activity);
    }

    public function manage(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor, Activity $activity): bool
    {
        return $this->canManageActivity($actor, $activity);
    }

    public function publish(User $actor, Activity $activity): bool
    {
        return $this->canManageActivity($actor, $activity);
    }

    public function cancel(User $actor, Activity $activity): bool
    {
        return $this->canManageActivity($actor, $activity);
    }

    public function complete(User $actor, Activity $activity): bool
    {
        return $this->canManageActivity($actor, $activity);
    }

    public function viewHistory(User $actor, Activity $activity): bool
    {
        return $this->canManageActivity($actor, $activity);
    }

    private function canManage(User $actor): bool
    {
        if (! $actor->isActive()) {
            return false;
        }

        if ($actor->hasRole(Role::ADMINISTRATOR)) {
            return true;
        }

        return $actor->hasRole(Role::DEPARTMENT_PERSONNEL)
            && $actor->departmentPersonnelPrograms()->exists();
    }

    private function canManageActivity(User $actor, Activity $activity): bool
    {
        if (! $this->canManage($actor)) {
            return false;
        }

        if ($actor->hasRole(Role::ADMINISTRATOR)) {
            return true;
        }

        if ($activity->audience_scope !== 'custom') {
            return false;
        }

        $activity->loadMissing('targets:id,activity_id,role_id,program_id,year_level_id,section_id,user_id');
        $assignedProgramIds = ActivityAudience::assignedProgramIds($actor);
        $programIds = $activity->targets
            ->whereNotNull('program_id')
            ->pluck('program_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($programIds === [] || array_diff($programIds, $assignedProgramIds) !== []) {
            return false;
        }

        $sectionIds = $activity->targets
            ->whereNotNull('section_id')
            ->pluck('section_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($sectionIds !== []) {
            $validSectionIds = Section::query()
                ->whereIn('id', $sectionIds)
                ->whereIn('program_id', $assignedProgramIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (array_diff($sectionIds, $validSectionIds) !== []) {
                return false;
            }
        }

        $userIds = $activity->targets
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return true;
        }

        return User::query()
            ->with([
                'student:id,user_id,program_id',
                'professor:id,user_id,program_id',
            ])
            ->whereIn('id', $userIds)
            ->get()
            ->every(function (User $user) use ($assignedProgramIds): bool {
                $programId = $user->student?->program_id ?? $user->professor?->program_id;

                return $programId !== null && in_array((int) $programId, $assignedProgramIds, true);
            });
    }
}

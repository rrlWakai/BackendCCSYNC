<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Support\AnnouncementAudience;

final class AnnouncementPolicy
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

    public function view(User $actor, Announcement $announcement): bool
    {
        return AnnouncementAudience::isVisible($announcement, $actor);
    }

    public function manage(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor, Announcement $announcement): bool
    {
        return $this->canManageAnnouncement($actor, $announcement);
    }

    public function publish(User $actor, Announcement $announcement): bool
    {
        return $this->canManageAnnouncement($actor, $announcement);
    }

    public function archive(User $actor, Announcement $announcement): bool
    {
        return $this->canManageAnnouncement($actor, $announcement);
    }

    public function viewHistory(User $actor, Announcement $announcement): bool
    {
        return $this->canManageAnnouncement($actor, $announcement);
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

    private function canManageAnnouncement(User $actor, Announcement $announcement): bool
    {
        if (! $this->canManage($actor)) {
            return false;
        }

        if ($actor->hasRole(Role::ADMINISTRATOR)) {
            return true;
        }

        if ($announcement->audience_scope !== 'custom') {
            return false;
        }

        $announcement->loadMissing([
            'targets:id,announcement_id,role_id,program_id,year_level_id,section_id,user_id',
        ]);

        $assignedProgramIds = $actor->departmentPersonnelPrograms()
            ->pluck('programs.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $programIds = $announcement->targets
            ->whereNotNull('program_id')
            ->pluck('program_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($programIds === [] || array_diff($programIds, $assignedProgramIds) !== []) {
            return false;
        }

        $sectionIds = $announcement->targets
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

        $userIds = $announcement->targets
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

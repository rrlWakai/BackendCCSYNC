<?php

namespace App\Policies;

use App\Models\ClassSchedule;
use App\Models\Role;
use App\Models\User;

final class ClassSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }

    public function view(User $user, ClassSchedule $schedule): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL)) {
            return true;
        }

        if ($schedule->status !== 'published') {
            return false;
        }

        if ($user->hasRole(Role::PROFESSOR)
            && $user->professor !== null
            && $user->professor->status === 'active') {
            return $schedule->scheduleAssignments()
                ->where('status', 'active')
                ->whereHas('facultyAssignment', fn ($query) => $query
                    ->where('professor_id', $user->professor->id)
                    ->where('status', 'active'))
                ->exists();
        }

        if ($user->hasRole(Role::STUDENT)
            && $user->student !== null
            && $user->student->status === 'active') {
            return $user->student->enrollments()
                ->where('status', 'enrolled')
                ->where('subject_offering_id', $schedule->subject_offering_id)
                ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, ClassSchedule $schedule): bool
    {
        return $this->canManage($user);
    }

    public function publish(User $user, ClassSchedule $schedule): bool
    {
        return $this->canManage($user);
    }

    public function cancel(User $user, ClassSchedule $schedule): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return $user->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }
}

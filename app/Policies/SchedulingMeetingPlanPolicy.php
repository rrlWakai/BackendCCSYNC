<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\SchedulingMeetingPlan;
use App\Models\User;

final class SchedulingMeetingPlanPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function view(User $actor, SchedulingMeetingPlan $plan): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor, SchedulingMeetingPlan $plan): bool
    {
        return $this->canManage($actor);
    }

    public function delete(User $actor, SchedulingMeetingPlan $plan): bool
    {
        return $this->canManage($actor);
    }

    public function approve(User $actor, SchedulingMeetingPlan $plan): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR);
    }

    public function revise(User $actor, SchedulingMeetingPlan $plan): bool
    {
        return $this->canManage($actor);
    }

    private function canManage(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }
}

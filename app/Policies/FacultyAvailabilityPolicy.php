<?php

namespace App\Policies;

use App\Models\FacultyAvailability;
use App\Models\Role;
use App\Models\User;

final class FacultyAvailabilityPolicy
{
    public function manage(User $actor, FacultyAvailability $availability): bool
    {
        if (! $actor->isActive()) {
            return false;
        }

        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL)
            || ($actor->hasRole(Role::PROFESSOR)
                && $availability->professor?->user_id === $actor->id);
    }
}

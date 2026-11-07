<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Room;
use App\Models\User;

final class RoomPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function view(User $actor, Room $room): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor, Room $room): bool
    {
        return $this->canManage($actor);
    }

    public function changeStatus(User $actor, Room $room): bool
    {
        return $this->canManage($actor);
    }

    private function canManage(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR, Role::DEPARTMENT_PERSONNEL);
    }
}

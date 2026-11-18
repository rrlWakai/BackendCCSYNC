<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

final class PromotionRunPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function view(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function finalize(User $actor): bool
    {
        return $this->canManage($actor);
    }

    private function canManage(User $actor): bool
    {
        return $actor->hasRole(Role::ADMINISTRATOR);
    }
}

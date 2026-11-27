<?php

namespace App\Policies;

use App\Models\Monitor;
use App\Models\User;

/**
 * Enforces fail-closed single-owner authorization for all monitor CRUD and state actions.
 */
class MonitorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Monitor $monitor): bool
    {
        return $this->ownsMonitor($user, $monitor);
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Monitor $monitor): bool
    {
        return $this->ownsMonitor($user, $monitor);
    }

    public function delete(User $user, Monitor $monitor): bool
    {
        return $this->ownsMonitor($user, $monitor);
    }

    public function pause(User $user, Monitor $monitor): bool
    {
        return $this->ownsMonitor($user, $monitor);
    }

    public function resume(User $user, Monitor $monitor): bool
    {
        return $this->ownsMonitor($user, $monitor);
    }

    private function ownsMonitor(User $user, Monitor $monitor): bool
    {
        return $user->isOwner() && (int) $monitor->user_id === (int) $user->getKey();
    }
}

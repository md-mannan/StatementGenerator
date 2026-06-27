<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;
use App\Policies\Concerns\ChecksClientOwnership;

class BranchPolicy
{
    use ChecksClientOwnership;

    public function view(User $user, Branch $branch): bool
    {
        return $this->ownsClientId($user, $branch->client->user_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->ownsClientId($user, $branch->client->user_id);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $this->ownsClientId($user, $branch->client->user_id);
    }

    public function import(User $user, Branch $branch): bool
    {
        return $this->ownsClientId($user, $branch->client->user_id);
    }

    public function export(User $user, Branch $branch): bool
    {
        return $this->ownsClientId($user, $branch->client->user_id);
    }
}

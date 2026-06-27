<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function view(User $user, Branch $branch): bool
    {
        return $branch->client->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Branch $branch): bool
    {
        return $branch->client->user_id === $user->id;
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $branch->client->user_id === $user->id;
    }

    public function import(User $user, Branch $branch): bool
    {
        return $branch->client->user_id === $user->id;
    }

    public function export(User $user, Branch $branch): bool
    {
        return $branch->client->user_id === $user->id;
    }
}

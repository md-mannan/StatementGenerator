<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\ChecksClientOwnership;

class ClientPolicy
{
    use ChecksClientOwnership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        return $this->ownsClient($user, $client);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Client $client): bool
    {
        return $this->ownsClient($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->ownsClient($user, $client);
    }
}

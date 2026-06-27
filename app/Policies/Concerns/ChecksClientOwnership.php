<?php

namespace App\Policies\Concerns;

use App\Models\Client;
use App\Models\User;

trait ChecksClientOwnership
{
    protected function ownsClient(User $user, Client $client): bool
    {
        return $this->ownsClientId($user, $client->user_id);
    }

    protected function ownsClientId(User $user, mixed $clientUserId): bool
    {
        return (int) $clientUserId === (int) $user->getAuthIdentifier();
    }
}

<?php

namespace App\Policies;

use App\Models\ClientAnnexureEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksClientOwnership;

class ClientAnnexureEntryPolicy
{
    use ChecksClientOwnership;

    public function update(User $user, ClientAnnexureEntry $clientAnnexureEntry): bool
    {
        return $this->ownsClientId($user, $clientAnnexureEntry->client->user_id);
    }

    public function delete(User $user, ClientAnnexureEntry $clientAnnexureEntry): bool
    {
        return $this->ownsClientId($user, $clientAnnexureEntry->client->user_id);
    }
}

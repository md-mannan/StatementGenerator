<?php

namespace App\Policies;

use App\Models\ClientAnnexureEntry;
use App\Models\User;

class ClientAnnexureEntryPolicy
{
    public function update(User $user, ClientAnnexureEntry $clientAnnexureEntry): bool
    {
        return $clientAnnexureEntry->client->user_id === $user->id;
    }

    public function delete(User $user, ClientAnnexureEntry $clientAnnexureEntry): bool
    {
        return $clientAnnexureEntry->client->user_id === $user->id;
    }
}

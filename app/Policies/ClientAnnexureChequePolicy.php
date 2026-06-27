<?php

namespace App\Policies;

use App\Models\ClientAnnexureCheque;
use App\Models\User;

class ClientAnnexureChequePolicy
{
    public function update(User $user, ClientAnnexureCheque $clientAnnexureCheque): bool
    {
        return $clientAnnexureCheque->client->user_id === $user->id;
    }

    public function delete(User $user, ClientAnnexureCheque $clientAnnexureCheque): bool
    {
        return $clientAnnexureCheque->client->user_id === $user->id;
    }
}

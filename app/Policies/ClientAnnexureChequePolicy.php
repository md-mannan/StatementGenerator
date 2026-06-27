<?php

namespace App\Policies;

use App\Models\ClientAnnexureCheque;
use App\Models\User;
use App\Policies\Concerns\ChecksClientOwnership;

class ClientAnnexureChequePolicy
{
    use ChecksClientOwnership;

    public function update(User $user, ClientAnnexureCheque $clientAnnexureCheque): bool
    {
        return $this->ownsClientId($user, $clientAnnexureCheque->client->user_id);
    }

    public function delete(User $user, ClientAnnexureCheque $clientAnnexureCheque): bool
    {
        return $this->ownsClientId($user, $clientAnnexureCheque->client->user_id);
    }
}

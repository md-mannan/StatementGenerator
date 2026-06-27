<?php

namespace App\Policies;

use App\Models\IncomingStatementEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksClientOwnership;

class IncomingStatementEntryPolicy
{
    use ChecksClientOwnership;

    public function update(User $user, IncomingStatementEntry $incomingStatementEntry): bool
    {
        return $this->ownsClientId($user, $incomingStatementEntry->client->user_id);
    }

    public function delete(User $user, IncomingStatementEntry $incomingStatementEntry): bool
    {
        return $this->ownsClientId($user, $incomingStatementEntry->client->user_id);
    }
}

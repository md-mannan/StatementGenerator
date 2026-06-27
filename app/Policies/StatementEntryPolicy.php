<?php

namespace App\Policies;

use App\Models\StatementEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksClientOwnership;

class StatementEntryPolicy
{
    use ChecksClientOwnership;

    public function update(User $user, StatementEntry $statementEntry): bool
    {
        return $this->ownsClientId($user, $statementEntry->branch->client->user_id);
    }

    public function delete(User $user, StatementEntry $statementEntry): bool
    {
        return $this->ownsClientId($user, $statementEntry->branch->client->user_id);
    }
}

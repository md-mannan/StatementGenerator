<?php

namespace App\Policies;

use App\Models\StatementEntry;
use App\Models\User;

class StatementEntryPolicy
{
    public function update(User $user, StatementEntry $statementEntry): bool
    {
        return $statementEntry->branch->client->user_id === $user->id;
    }

    public function delete(User $user, StatementEntry $statementEntry): bool
    {
        return $statementEntry->branch->client->user_id === $user->id;
    }
}

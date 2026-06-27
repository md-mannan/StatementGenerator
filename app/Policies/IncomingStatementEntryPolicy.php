<?php

namespace App\Policies;

use App\Models\IncomingStatementEntry;
use App\Models\User;

class IncomingStatementEntryPolicy
{
    public function update(User $user, IncomingStatementEntry $incomingStatementEntry): bool
    {
        return $incomingStatementEntry->client->user_id === $user->id;
    }

    public function delete(User $user, IncomingStatementEntry $incomingStatementEntry): bool
    {
        return $incomingStatementEntry->client->user_id === $user->id;
    }
}

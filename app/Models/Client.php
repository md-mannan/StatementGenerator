<?php

namespace App\Models;

use App\Policies\ClientPolicy;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'name'])]
#[UsePolicy(ClientPolicy::class)]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<IncomingStatementEntry, $this>
     */
    public function incomingStatementEntries(): HasMany
    {
        return $this->hasMany(IncomingStatementEntry::class);
    }

    /**
     * @return HasMany<ClientAnnexure, $this>
     */
    public function annexures(): HasMany
    {
        return $this->hasMany(ClientAnnexure::class);
    }

    /**
     * @return HasMany<ClientAnnexureEntry, $this>
     */
    public function annexureEntries(): HasMany
    {
        return $this->hasMany(ClientAnnexureEntry::class);
    }
}

<?php

namespace App\Models;

use App\Policies\BranchPolicy;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $client_id
 * @property string $code
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'code', 'name'])]
#[UsePolicy(BranchPolicy::class)]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<StatementEntry, $this>
     */
    public function statementEntries(): HasMany
    {
        return $this->hasMany(StatementEntry::class);
    }
}

<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;

/**
 * @property string $id
 * @property string $pairing_id
 * @property PairingKeyOwner $owner
 * @property string $public_key
 * @property string|null $private_key Decrypted automatically by the `encrypted` cast. Never expose this outside the package.
 * @property PairingKeyStatus $status
 * @property Carbon|null $activated_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Pairing $pairing
 */
class PairingKey extends Model
{
    use HasUlids;

    protected $table = 'pairing_keys';

    protected $fillable = [
        'pairing_id',
        'owner',
        'public_key',
        'private_key',
        'status',
        'activated_at',
        'revoked_at',
    ];

    protected $casts = [
        'owner' => PairingKeyOwner::class,
        'status' => PairingKeyStatus::class,
        'private_key' => 'encrypted',
        'activated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'private_key',
    ];

    /**
     * @return BelongsTo<Pairing, $this>
     */
    public function pairing(): BelongsTo
    {
        return $this->belongsTo(Pairing::class, 'pairing_id');
    }

    public function isLocal(): bool
    {
        return $this->owner === PairingKeyOwner::Local;
    }
}

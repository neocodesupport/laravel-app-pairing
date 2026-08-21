<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Neocode\LaravelAppPairing\Enums\PairingRequestDirection;
use Neocode\LaravelAppPairing\Enums\PairingRequestStatus;

/**
 * @property string $id
 * @property string $request_id
 * @property PairingRequestDirection $direction
 * @property string|null $pairing_id
 * @property string|null $remote_app_id
 * @property string|null $remote_name
 * @property string|null $remote_base_url
 * @property string|null $remote_environment
 * @property string|null $remote_api_version
 * @property string|null $remote_public_key
 * @property PairingRequestStatus $status
 * @property Carbon $requested_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Pairing|null $pairing
 */
class PairingRequest extends Model
{
    use HasUlids;

    protected $table = 'pairing_requests';

    protected $fillable = [
        'request_id',
        'direction',
        'pairing_id',
        'remote_app_id',
        'remote_name',
        'remote_base_url',
        'remote_environment',
        'remote_api_version',
        'remote_public_key',
        'status',
        'requested_at',
        'responded_at',
        'expires_at',
    ];

    protected $casts = [
        'direction' => PairingRequestDirection::class,
        'status' => PairingRequestStatus::class,
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Pairing, $this>
     */
    public function pairing(): BelongsTo
    {
        return $this->belongsTo(Pairing::class, 'pairing_id');
    }

    public function isPending(): bool
    {
        return $this->status === PairingRequestStatus::Pending;
    }
}

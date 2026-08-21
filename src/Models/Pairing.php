<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Neocode\LaravelAppPairing\Enums\PairingKeyOwner;
use Neocode\LaravelAppPairing\Enums\PairingKeyStatus;
use Neocode\LaravelAppPairing\Enums\PairingStatus;

/**
 * @property string $id
 * @property string $pairing_uuid
 * @property string $local_application_id
 * @property string|null $remote_application_id
 * @property string|null $remote_name
 * @property string|null $remote_base_url
 * @property PairingStatus $status
 * @property Carbon|null $requested_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_communication_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Application $localApplication
 * @property-read Application|null $remoteApplication
 */
class Pairing extends Model
{
    use HasUlids;

    protected $table = 'pairing_pairings';

    protected $fillable = [
        'pairing_uuid',
        'local_application_id',
        'remote_application_id',
        'remote_name',
        'remote_base_url',
        'status',
        'requested_at',
        'accepted_at',
        'revoked_at',
        'last_communication_at',
    ];

    protected $casts = [
        'status' => PairingStatus::class,
        'requested_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_communication_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Application, $this>
     */
    public function localApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'local_application_id');
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function remoteApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'remote_application_id');
    }

    /**
     * @return HasMany<PairingRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(PairingRequest::class, 'pairing_id');
    }

    /**
     * @return HasMany<PairingKey, $this>
     */
    public function keys(): HasMany
    {
        return $this->hasMany(PairingKey::class, 'pairing_id');
    }

    public function activeLocalKey(): ?PairingKey
    {
        return $this->keys()
            ->where('owner', PairingKeyOwner::Local)
            ->where('status', PairingKeyStatus::Active)
            ->latest('activated_at')
            ->first();
    }

    /**
     * @return HasMany<PairingKey, $this>
     */
    public function activeRemoteKeys(): HasMany
    {
        return $this->keys()
            ->where('owner', PairingKeyOwner::Remote)
            ->where('status', PairingKeyStatus::Active);
    }

    /**
     * Remote public keys eligible to verify an incoming signature: the
     * currently active one, plus any recently rotated-out key still inside
     * its rotation grace period (so in-flight requests signed just before a
     * rotation was received are not spuriously rejected).
     *
     * @return HasMany<PairingKey, $this>
     */
    public function remoteKeysEligibleForVerification(int $graceSeconds): HasMany
    {
        return $this->keys()
            ->where('owner', PairingKeyOwner::Remote)
            ->where(function (Builder $query) use ($graceSeconds): void {
                $query->where('status', PairingKeyStatus::Active)
                    ->orWhere(function (Builder $query) use ($graceSeconds): void {
                        $query->where('status', PairingKeyStatus::Revoked)
                            ->where('revoked_at', '>', now()->subSeconds($graceSeconds));
                    });
            });
    }

    public function isActive(): bool
    {
        return $this->status === PairingStatus::Active;
    }
}

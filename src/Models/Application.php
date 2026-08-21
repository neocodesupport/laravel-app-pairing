<?php

declare(strict_types=1);

namespace Neocode\LaravelAppPairing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A known application: either this very installation (is_local = true, at
 * most one such row exists) or a remote partner application it has
 * exchanged pairing requests with.
 *
 * @property string $id
 * @property string $app_id
 * @property string $name
 * @property string $base_url
 * @property string $environment
 * @property string $api_version
 * @property bool $is_local
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Application extends Model
{
    use HasUlids;

    protected $table = 'pairing_applications';

    protected $fillable = [
        'app_id',
        'name',
        'base_url',
        'environment',
        'api_version',
        'is_local',
    ];

    protected $casts = [
        'is_local' => 'boolean',
    ];

    /**
     * @return HasMany<Pairing, $this>
     */
    public function pairingsAsLocal(): HasMany
    {
        return $this->hasMany(Pairing::class, 'local_application_id');
    }

    /**
     * @return HasMany<Pairing, $this>
     */
    public function pairingsAsRemote(): HasMany
    {
        return $this->hasMany(Pairing::class, 'remote_application_id');
    }
}

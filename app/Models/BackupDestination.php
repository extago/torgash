<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Where backups are kept (PLAN §67.1): the shop's own disk, a folder on the
 * server, a bucket. Passwords and keys are `secrets`, encrypted with APP_KEY —
 * on another server (another key) they are typed again, by design.
 */
class BackupDestination extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'array',
        'system' => 'boolean',
    ];

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(BackupPlan::class, 'backup_plan_destination', 'destination_id', 'plan_id')->withPivot('keep');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** @return array<string, string> empty when there are none or they cannot be read (another APP_KEY) */
    public function secrets(): array
    {
        if (! $this->secrets) {
            return [];
        }

        try {
            return (array) json_decode(Crypt::decryptString($this->secrets), true);
        } catch (Throwable) {
            return [];
        }
    }

    /** True when there are secrets this server can no longer read. */
    public function secretsLost(): bool
    {
        return (bool) $this->secrets && $this->secrets() === [];
    }

    public function secret(string $key): string
    {
        return (string) ($this->secrets()[$key] ?? '');
    }

    /** @param  array<string, string>  $secrets */
    public function putSecrets(array $secrets): void
    {
        $secrets = array_filter($secrets, fn ($value) => $value !== '' && $value !== null);
        $this->secrets = $secrets ? Crypt::encryptString(json_encode($secrets)) : null;
    }
}

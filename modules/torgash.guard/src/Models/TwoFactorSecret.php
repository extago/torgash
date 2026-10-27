<?php

namespace Torgash\Guard\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's second factor.
 *
 * @property string $secret
 * @property array|null $recovery_codes
 * @property Carbon|null $confirmed_at
 * @property int|null $last_timestep
 */
class TwoFactorSecret extends Model
{
    protected $table = 'm_guard_two_factor';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Two-factor sign-in';
    }

    /**
     * Both credentials are ENCRYPTED, not hashed, and the difference is forced
     * rather than chosen: TOTP is a sum the shop has to be able to compute too,
     * so there is no one-way version of the secret. The recovery codes follow
     * it for a reason given in the migration — they sit behind the same key, so
     * hashing them would protect nothing extra and would cost the person the
     * ability to look at their own list twice.
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Armed. An unconfirmed row is an enrolment somebody walked away from. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at');
    }

    public function codesLeft(): int
    {
        return count($this->recovery_codes ?? []);
    }
}

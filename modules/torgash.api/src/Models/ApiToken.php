<?php

namespace Torgash\Api\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $table = 'm_api_tokens';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'API token';
    }

    /** Long enough that guessing is not a strategy, short enough to paste. */
    protected const BYTES = 32;

    /** So a token is recognisable in a log or a config file as ours. */
    public const PREFIX = 'tg_';

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mints one and returns [$token, $plaintext]. The plaintext is the only
     * time it exists anywhere: the row keeps a hash, so this is shown to the
     * admin once and cannot be recovered afterwards — which is the property
     * that makes a leaked database useless against the API.
     *
     * @param  list<string>|null  $abilities
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $name, ?array $abilities = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $plaintext = self::PREFIX.Str::random(self::BYTES * 2);

        $token = self::create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => self::hash($plaintext),
            'abilities' => $abilities ?: null,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plaintext];
    }

    /**
     * sha256 and not bcrypt, deliberately: the secret is 64 random characters,
     * so there is nothing to brute-force and nothing a slow hash would buy —
     * while a slow hash on EVERY request would be a self-inflicted rate limit.
     * The same reasoning every framework's token guard uses.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Null abilities means "whatever its owner may do" — narrowing is opt-in. */
    public function allows(string $ability): bool
    {
        return $this->abilities === null || in_array($ability, $this->abilities, true);
    }
}

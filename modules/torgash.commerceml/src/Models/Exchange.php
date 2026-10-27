<?php

namespace Torgash\CommerceMl\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One conversation with 1C, from checkauth to the last "success".
 *
 * The protocol spreads a single import across many requests — authorise, ask
 * the limits, send the file in pieces, then call import over and over while the
 * shop answers "progress". None of that fits in a session, because the endpoint
 * has none: a machine has no cookies of ours, no CSRF token and no locale
 * prefix, the same reasoning as the payments callback.
 *
 * So the conversation is a ROW, addressed by a token, exactly as a cart is. It
 * doubles as the log: when it finished, what it did, and what went wrong.
 */
class Exchange extends Model
{
    protected $table = 'm_commerceml_exchanges';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return '1C exchange session';
    }

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** Where this exchange's files land — one directory each, never shared. */
    public function file(string $name): string
    {
        return $this->path.DIRECTORY_SEPARATOR.$name;
    }

    public function note(string $message): void
    {
        $this->forceFill(['message' => $message])->save();
    }

    public function finish(string $state, ?string $message = null): void
    {
        $this->forceFill([
            'state' => $state,
            'message' => $message ?? $this->message,
            'finished_at' => now(),
        ])->save();
    }
}

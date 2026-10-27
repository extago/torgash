<?php

namespace Torgash\Giftcards\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Torgash\Giftcards\Codes;

/**
 * One gift card. Its balance is the sum of its ledger (Ledger writes both in
 * one transaction); its state is WORKED OUT from four facts — the stored
 * status, the balance, the date and whether it has been handed over — so a
 * card cannot say "active" about a date that has passed.
 */
class Card extends Model
{
    public const ACTIVE = 'active';

    /** Printed and in a drawer: not money until somebody hands it over. */
    public const PENDING = 'pending';

    public const DISABLED = 'disabled';

    protected $table = 'm_giftcards_cards';

    protected $guarded = [];

    protected $attributes = [
        'status' => self::ACTIVE,
        'balance' => 0,
        'source' => 'issued',
    ];

    protected function casts(): array
    {
        return [
            'initial_amount' => 'int',
            'balance' => 'int',
            'rules' => 'array',
            'expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'deliver_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public static function findByCode(string $typed): ?self
    {
        $code = Codes::normalise($typed);

        return Codes::looksLikeCard($code) ? static::where('code_hash', Codes::hash($code))->first() : null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'card_id')->orderByDesc('id');
    }

    /** The code, for the one who may see it (the admin, the page behind the token). */
    public function code(): string
    {
        return Crypt::decryptString($this->code_encrypted);
    }

    public function formattedCode(): string
    {
        return Codes::format($this->code());
    }

    /** ····1234 — how a card is named wherever its code must not be. */
    public function masked(): string
    {
        return '····'.$this->last4;
    }

    /**
     * active | pending | redeemed | expired | disabled — in that order of
     * precedence from the bottom: a disabled card is disabled whatever else
     * is true of it.
     */
    public function state(): string
    {
        return match (true) {
            $this->status === self::DISABLED => 'disabled',
            $this->status === self::PENDING => 'pending',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            (int) $this->balance <= 0 => 'redeemed',
            default => 'active',
        };
    }

    /**
     * The worked-out states in SQL, mirroring state() exactly — the list's
     * tabs and the API's filter ask this, never a copy of it.
     */
    public function scopeInState($query, string $state)
    {
        $notExpired = fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());

        return match ($state) {
            'disabled' => $query->where('status', self::DISABLED),
            'pending' => $query->where('status', self::PENDING),
            'expired' => $query->where('status', self::ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<', now()),
            'redeemed' => $query->where('status', self::ACTIVE)->where('balance', '<=', 0)->where($notExpired),
            default => $query->where('status', self::ACTIVE)->where('balance', '>', 0)->where($notExpired),
        };
    }

    public function isUsable(): bool
    {
        return $this->state() === 'active';
    }

    /** English source words; the Russian is in the module's lang file. */
    public static function stateLabel(string $state): string
    {
        return __(match ($state) {
            'active' => 'works',
            'pending' => 'not handed over',
            'redeemed' => 'spent',
            'expired' => 'expired',
            'disabled' => 'off',
            default => $state,
        });
    }

    public static function stateBadge(string $state): string
    {
        return match ($state) {
            'active' => 'badge-success',
            'pending' => 'badge-info',
            'redeemed' => 'badge-neutral',
            'expired' => 'badge-warning',
            default => 'badge-ghost',
        };
    }

    /** The page the card is sent as — its token, never its code. */
    public function publicUrl(): string
    {
        return route('giftcards.show', $this->public_token);
    }

    /** @return array<string, mixed> the card's own rules with the shop's defaults filled in */
    public function rule(string $key, mixed $default = null): mixed
    {
        return ($this->rules ?? [])[$key] ?? $default;
    }
}

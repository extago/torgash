<?php

namespace Torgash\Cart\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A cart, addressed by a token rather than by whoever happens to be holding a
 * session.
 *
 * The model is deliberately thin: CartManager is where a cart is read, priced
 * and reasoned about, and everything else in the shop talks to that. This is
 * the row it keeps.
 */
class Cart extends Model
{
    protected $table = 'm_cart_carts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'checkout' => 'array',
            'last_active_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 64 random characters. Not a uuid: a uuid says when it was made and, in
     * some versions, on which machine — a cart's address should say nothing at
     * all, because the address IS the authorisation.
     */
    public static function newToken(): string
    {
        return Str::random(64);
    }

    /**
     * The lines, as {@see \Torgash\Cart\Lines} spells them. A list, not a map:
     * one product can be in a cart twice, configured two ways (PLAN §33.3).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function store(array $items): void
    {
        $this->forceFill([
            'items' => $items,
            'last_active_at' => now(),
        ])->save();
    }
}

<?php

namespace Torgash\Giftcards\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a card's balance, signed: `+issue`, `−redeem(order)`,
 * `+refund(order)`, `±adjust(person, reason)`, `−expire`, `−void`. Written
 * only by Ledger, never updated — a correction is another line.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'm_giftcards_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'int',
            'created_at' => 'datetime',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'card_id');
    }

    /** English source words for the history, translated when read. */
    public function typeLabel(): string
    {
        return __(match ($this->type) {
            'issue' => 'Issue of the card',
            'redeem' => 'Paid for an order',
            'refund' => 'Returned from an order',
            'adjust' => 'Corrected by hand',
            'expire' => 'Expiry',
            'void' => 'Cancelled',
            default => (string) $this->type,
        });
    }
}

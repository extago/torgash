<?php

namespace Torgash\Rma\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Torgash\Checkout\Models\Order;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Rma\State;

/**
 * A return, as the shop acts on it. The conversation about it is the enquiry.
 *
 * Named ReturnRequest and not Return because `return` is a PHP keyword — the
 * table is `m_rma_returns` and the language is the only reason for the
 * difference.
 *
 * @property string $resolution refund | exchange
 * @property string $state
 * @property array $lines
 */
class ReturnRequest extends Model
{
    protected $table = 'm_rma_returns';

    protected $guarded = [];

    public const REFUND = 'refund';

    public const EXCHANGE = 'exchange';

    /** What happens to the goods once they are here. */
    public const RESTOCK = 'restock';

    public const WRITEOFF = 'writeoff';

    /**
     * What is left to settle in money after an exchange. Three answers because
     * two would not cover it: the shop can owe as easily as the customer, and
     * "nothing" is the ordinary case that must be recorded rather than left
     * blank — blank means nobody has decided.
     */
    public const SURCHARGE_NONE = 'none';

    public const SURCHARGE_DUE = 'due';

    public const SURCHARGE_OWED = 'owed';

    protected $attributes = [
        'state' => State::REQUESTED,
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /**
     * The order it is about. A relation without a foreign key in the schema —
     * this module may not own checkout's table — so a deleted order gives null
     * and the screens say so rather than failing.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function exchangeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'exchange_order_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('state', [State::DECLINED, State::COMPLETED]);
    }

    public function isExchange(): bool
    {
        return $this->resolution === self::EXCHANGE;
    }

    /**
     * What the goods being returned came to on the original order.
     *
     * The starting point for a refund, not the answer: a shop may keep the
     * postage or agree on something else, which is why the amount is typed in
     * and then stored rather than recomputed from here.
     */
    public function linesTotal(): int
    {
        return (int) collect($this->lines)->sum(fn (array $line) => (int) ($line['line_total'] ?? 0));
    }

    /** @return array<string, string> */
    public static function resolutions(): array
    {
        return [
            self::REFUND => __('Refund the money'),
            self::EXCHANGE => __('Exchange for the same thing'),
        ];
    }

    public function resolutionLabel(): string
    {
        return self::resolutions()[$this->resolution] ?? $this->resolution;
    }

    /** @return array<string, string> */
    public static function dispositions(): array
    {
        return [
            self::RESTOCK => __('Put back into stock'),
            self::WRITEOFF => __('Write it off'),
        ];
    }

    /** @return array<string, string> */
    public static function surcharges(): array
    {
        return [
            self::SURCHARGE_NONE => __('Nothing more to settle'),
            self::SURCHARGE_DUE => __('The customer owes the difference'),
            self::SURCHARGE_OWED => __('We owe the customer the difference'),
        ];
    }

    public function surchargeLabel(): ?string
    {
        return $this->surcharge ? (self::surcharges()[$this->surcharge] ?? $this->surcharge) : null;
    }

    /**
     * What the shop may do to it next.
     *
     * One answer, asked by the card, the API and the tests alike — a screen
     * drawing buttons from its own idea of the order of things is how the API
     * ends up allowing what the screen forbids. Receiving is allowed straight
     * from `requested` on purpose: parcels arrive before anybody has clicked
     * Approve, and refusing to record that would only mean recording it wrong.
     *
     * @return list<string>
     */
    public function allows(): array
    {
        if (State::isFinished($this->state)) {
            return [];
        }

        $can = ['decline'];

        if ($this->state === State::REQUESTED) {
            $can[] = 'approve';
        }

        if (! $this->received_at) {
            $can[] = 'receive';
        }

        // Money and a replacement are both settlements, and either ends the
        // return. Offered once the goods are here — a shop that settles before
        // seeing them does so by receiving first, which is what happened.
        //
        // What the customer ASKED for comes first, and the other stays on
        // offer: "we have nothing to swap it for" is a real answer, and it is
        // settled by refunding.
        if ($this->received_at) {
            $can = [...$can, ...($this->isExchange() ? ['exchange', 'refund'] : ['refund', 'exchange'])];
        }

        return $can;
    }
}

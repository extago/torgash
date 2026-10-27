<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    /** Where the sign stands: "$1", "$ 1", "1 $", "1$". */
    public const SYMBOL_POSITIONS = ['before', 'before_space', 'after_space', 'after'];

    protected $guarded = [];

    /** The database's own defaults, so a fresh model formats like a stored one. */
    protected $attributes = [
        'symbol_position' => 'after_space',
        'decimal_separator' => '.',
        'thousands_separator' => ' ',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            // Not decimal:N — that would round a rate back to N places on read.
            'rate' => 'float',
            'auto_rate' => 'bool',
            'rate_updated_at' => 'datetime',
        ];
    }

    /**
     * An amount ALREADY in this currency (major units, a float) written the way
     * this currency is written. The one place that decides it: the storefront's
     * format_price() and an order printed at its frozen rate both end here, so
     * "$1,299.00" cannot be one thing on the product and another on the invoice.
     *
     * `$decimals` overrides how many places are shown — only format_price()'s
     * exact mode asks, for a sum the card is charged to the cent.
     */
    public function format(float $amount, ?int $decimals = null): string
    {
        $number = number_format(
            $amount,
            $decimals ?? (int) $this->decimals,
            (string) ($this->decimal_separator ?? '.'),
            (string) ($this->thousands_separator ?? ' '),
        );

        $written = match ($this->symbol_position) {
            'before' => $this->symbol.$number,
            'before_space' => $this->symbol.' '.$number,
            'after' => $number.$this->symbol,
            default => $number.' '.$this->symbol,
        };

        // An amount never breaks inside itself: "$7 440" wrapped as "$7" over
        // "440" in a narrow column. Every space in it — the thousands and the
        // one beside the symbol — is a no-break space, so every place that
        // prints money is safe without remembering `whitespace-nowrap`.
        return str_replace(' ', "\u{00A0}", $written);
    }
}

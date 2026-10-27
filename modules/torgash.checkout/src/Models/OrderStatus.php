<?php

namespace Torgash\Checkout\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    use HasTranslations;

    protected $table = 'm_checkout_order_statuses';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'is_default' => 'bool'];
    }

    /**
     * Where an order waits for money that has not arrived yet.
     *
     * A code and not a flag column: a status's code cannot be edited after it
     * is created (the form marks it `prohibited`), so it is a stable pointer,
     * and the name, colour and position stay the shop's to change. A shop that
     * deletes the row gets the old behaviour back — see awaitingPayment().
     */
    public const AWAITING_PAYMENT = 'awaiting_payment';

    /**
     * An order poured into another (PLAN §40.10/4): a shell holding no stock
     * and no money. CLOSED — nothing moves an order into it or out of it but
     * merging and undoing a merge (OrderStatusChanger refuses both ways), and
     * no list a person picks a status from offers it (`choosable()`).
     */
    public const MERGED = 'merged';

    /** The statuses a person or a rule may move an order to. */
    public static function choosable()
    {
        return static::where('code', '!=', self::MERGED)->orderBy('position');
    }

    public static function default(): self
    {
        return static::where('is_default', true)->first() ?? static::orderBy('position')->firstOrFail();
    }

    /**
     * Null when the shop has deleted it, and every caller treats that as "place
     * the order the way it always was" — the feature is subtractable.
     */
    public static function awaitingPayment(): ?self
    {
        return static::find(self::AWAITING_PAYMENT);
    }
}

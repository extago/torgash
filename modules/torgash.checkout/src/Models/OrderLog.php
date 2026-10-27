<?php

namespace Torgash\Checkout\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of an order's history.
 *
 * Stored as an English KEY plus its parameters (`params`) and translated when
 * READ — the rule background tasks and the guard's findings already live by.
 * The line is written by whoever made the change, in their language (or by a
 * worker, in the app's), and read by somebody else in theirs; a finished
 * sentence froze "Status set to Открыт" into every reader's English.
 *
 * A parameter is a plain value (an order number, a price already formatted,
 * a person's name) or one of two things resolved at read time too:
 * `self::word('unassigned')` — a declared English word through `__()` — and a
 * translatable map (`$status->name`, `{en, ru}`) through `pick_translation()`.
 */
class OrderLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'm_checkout_order_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array'];
    }

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Order history entry';
    }

    /**
     * Write a line. `$by` is who did it: the signed-in person by default, null
     * for the shop itself (a rule, a gateway, a timer).
     *
     * @param  array<string, mixed>  $params
     */
    public static function record(Order|int $order, string $key, array $params = [], ?int $by = -1): self
    {
        return static::create([
            'order_id' => $order instanceof Order ? $order->getKey() : $order,
            'user_id' => $by === -1 ? auth()->id() : $by,
            'description' => $key,
            'params' => $params ?: null,
        ]);
    }

    /** A declared English word as a parameter, translated when the line is read. */
    public static function word(string $english): array
    {
        return ['__' => $english];
    }

    /** The line in the reader's language. */
    public function text(): string
    {
        $params = [];

        foreach ((array) ($this->params ?? []) as $name => $value) {
            $params[$name] = match (true) {
                is_array($value) && isset($value['__']) => __((string) $value['__']),
                is_array($value) => (string) pick_translation($value),
                default => (string) $value,
            };
        }

        return __((string) $this->description, $params);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

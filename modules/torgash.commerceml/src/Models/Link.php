<?php

namespace Torgash\CommerceMl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What 1C calls something, and what the shop decided about it.
 *
 * A row is created the moment a name is SEEN — before anybody has decided
 * anything — which is what turns this table into a queue: the shop reads it in
 * the morning and says, per row, "that one is our wholesale price type", "make
 * one", or "never import anything carrying this".
 */
class Link extends Model
{
    protected $table = 'm_commerceml_links';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return '1C match';
    }

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    /** Seen, and waiting for a person. */
    public const ASK = 'ask';

    /** Use `local_id` / `local_key`. */
    public const LINK = 'link';

    /** Make one at the next exchange. */
    public const CREATE = 'create';

    /** Never import anything that carries this. */
    public const SKIP = 'skip';

    public const KIND_PRICE_TYPE = 'price_type';

    public const KIND_FEATURE = 'feature';

    public const KIND_FEATURE_VALUE = 'feature_value';

    public const KIND_ORDER_STATUS = 'order_status';

    /**
     * A value's key carries its property's, because 1C's value identifiers are
     * only unique inside their own property — two properties can both have a
     * variant whose id is the same string, and they are not the same variant.
     */
    public static function valueGuid(string $property, string $value): string
    {
        return $property.'#'.$value;
    }

    public function isDecided(): bool
    {
        return $this->decision !== self::ASK;
    }
}

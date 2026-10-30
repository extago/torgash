<?php

namespace Torgash\Promotions\Api;

use App\Core\Display\DisplayRules;
use Torgash\Api\ApiResources;
use Torgash\Promotions\Conditions;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

/**
 * Sales over the API.
 *
 * Read in full — a promotion is a container of ordered tiers and a caller that
 * cannot see the tiers cannot answer "why was this order 30.00 cheaper", which
 * is the only question anyone asks this resource. Written NARROWLY: `enabled`,
 * the two dates and the usage cap, which is the whole of "turn the sale on at
 * midnight and off when we have given away five hundred" — the reason to reach
 * for the API in the first place.
 *
 * What a PATCH deliberately cannot do is rewrite the tiers. A tier is an
 * ordered condition scope plus a discount whose value means different things
 * per type (a percent, minor units, a target price), and every one of those
 * conversions lives in the admin controller; a second spelling of it here is
 * the second source of truth this codebase keeps refusing to grow. A shop that
 * wants to build a sale from a script builds it in the admin and switches it
 * on from here.
 *
 * No `lookup`: a promotion is ours and nothing outside knew it before we did —
 * unlike a product's sku or an order's number. No `actions`: enabling a sale
 * IS setting a field, with no stock to move and no mail to send, so a plain
 * `update` is the honest shape (the same reasoning as the enquiries resource,
 * and the opposite of an order's status).
 */
class PromotionResources
{
    public function register(): void
    {
        ApiResources::register('admin.promotions', [
            'label' => 'Promotions',
            'description' => 'Automatic discounts — catalog-zone (a product’s own price drops) and cart-zone (money off the order, free shipping, a gift). Read whole, tiers included; switched on, scheduled and capped through PATCH. The tiers themselves are built in the admin.',
            'permission' => 'promotions.manage',
            'query' => fn () => Promotion::query()->with('tiers'),
            'sortable' => ['id', 'priority', 'starts_at', 'ends_at', 'used_count', 'created_at', 'updated_at'],
            'filters' => [
                'zone' => [
                    'description' => 'catalog | cart.',
                    'apply' => fn ($q, $v) => $q->where('zone', $v),
                ],
                'enabled' => [
                    'description' => 'The switch only. A promotion can be enabled and not running — see `status`.',
                    'apply' => fn ($q, $v) => $q->where('enabled', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
                ],
                'status' => [
                    'description' => 'running | paused | scheduled | ended | off — the switch, the calendar and the weekly window answered together, which is the question a caller actually has.',
                    'apply' => fn ($q, $v) => $this->byStatus($q, (string) $v),
                ],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'serialize' => fn (Promotion $promotion) => $this->promotion($promotion),
            'writable' => [
                'enabled' => ['type' => 'boolean', 'description' => 'The switch. Everything else about when it runs is the two dates.'],
                'starts_at' => ['type' => 'string|null', 'description' => 'ISO 8601, or null for "already". Frees a shop from having someone awake at midnight.'],
                'ends_at' => ['type' => 'string|null', 'description' => 'ISO 8601, or null for "until switched off".'],
                'usage_limit' => ['type' => 'integer|null', 'description' => 'Cart zone only. Null uncaps it. Not a way to reset the counter: `used_count` is a fact about orders that were placed.'],
                'priority' => ['type' => 'integer', 'description' => 'Lower goes first when two `replace` promotions want the same product or cart.'],
            ],
            'schema' => $this->schema(),
            'update' => function (Promotion $promotion, array $data) {
                // Narrow on purpose — see the class docblock. Anything else in
                // the body is ignored rather than half-applied.
                $promotion->fill(array_intersect_key(
                    $data,
                    array_flip(['enabled', 'starts_at', 'ends_at', 'usage_limit', 'priority']),
                ));

                $promotion->save();
            },
        ]);
    }

    /**
     * The four states as a query, mirroring Promotion::status() — which is the
     * authority, and stays it: this is the same three columns asked in SQL
     * because a filter cannot load every row to ask each one in PHP.
     */
    protected function byStatus($query, string $status)
    {
        $now = now();

        $inDates = fn ($q) => $q->where('enabled', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now));

        return match ($status) {
            'off' => $query->where('enabled', false),
            'scheduled' => $query->where('enabled', true)->where('starts_at', '>', $now),
            'ended' => $query->where('enabled', true)->where('ends_at', '<', $now),
            // In its dates but outside today's window, and its complement.
            // The window itself is not SQL (see Promotion::activeInZone), so
            // the ids of the paused ones are worked out in PHP over the only
            // rows that can possibly be paused — the ones carrying a schedule
            // — and the filter then stays a plain query the pager can page.
            'paused' => $inDates($query)->whereIn('id', $this->pausedIds()),
            'running' => $inDates($query)->whereNotIn('id', $this->pausedIds()),
            // An unknown word must not quietly mean "everything".
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** @return list<int> */
    protected function pausedIds(): array
    {
        return Promotion::query()
            ->whereNotNull('schedule')
            ->get(['id', 'schedule'])
            ->reject->isOpenNow()
            ->pluck('id')
            ->all();
    }

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        return [
            'id' => 'integer',
            'name' => ['type' => 'object', 'description' => 'Translatable: {"en": …, "ru": …}.'],
            'zone' => ['type' => 'string', 'description' => 'catalog — changes what a product costs. cart — takes money off an order.'],
            'status' => ['type' => 'string', 'description' => 'running | paused | scheduled | ended | off. Derived from the switch, the two dates and the repeating window, never stored. paused means "in its dates, outside the window it repeats in".'],
            'schedule' => ['type' => 'string|null', 'description' => 'The repeating window in words ("Mon, Fri 18:00–22:00"), or null when the sale is open whenever its dates say.'],
            'open_until' => ['type' => 'string|null', 'format' => 'date-time', 'description' => 'What a countdown counts to: whichever comes first, the close of the current window or the end of the sale.'],
            'enabled' => 'boolean',
            'combine' => ['type' => 'string', 'description' => 'replace — competes with other replace promotions on priority, highest-priority match wins. stack_add — sums with whatever else applied. stack_multiply — applies to the already-discounted price.'],
            'priority' => 'integer',
            'starts_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'ends_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'usage_limit' => ['type' => 'integer|null', 'description' => 'Cart zone only.'],
            'used_count' => ['type' => 'integer', 'description' => 'Redemptions recorded on placed orders. May exceed the limit by one under concurrent checkouts — the count is the truth, not the cap.'],
            'once_per_customer' => 'boolean',
            'applies_when' => [
                'type' => 'array',
                'description' => 'Where the sale runs — storefront, language, who is looking — in words, one string per condition. Empty means everywhere. A call is judged by the storefront it names in X-Storefront, so the same sale can be absent from this shop and present on the next.',
                'of' => ['type' => 'string'],
            ],
            'tiers' => [
                'type' => 'array',
                'description' => 'Tried top to bottom; the FIRST matching tier wins for a given product or cart. A tier with no conditions matches everything and is the sitewide fallback.',
                'of' => [
                    'position' => 'integer',
                    'match' => 'string',
                    'discount_type' => 'string',
                    'discount_value' => 'integer|null',
                    'discount' => 'string',
                    'conditions' => 'string',
                ],
            ],
            'created_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
        ];
    }

    /** @return array<string, mixed> */
    protected function promotion(Promotion $promotion): array
    {
        $conditionNames = Conditions::names($promotion->tiers);

        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'zone' => $promotion->zone,
            'status' => $promotion->status(),
            'schedule' => $promotion->scheduleWindows()->isEmpty() ? null : $promotion->describeSchedule(),
            'open_until' => $promotion->countdownEndsAt()?->toIso8601String(),
            'enabled' => (bool) $promotion->enabled,
            'combine' => $promotion->combine,
            'priority' => (int) $promotion->priority,
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'usage_limit' => $promotion->usage_limit,
            'used_count' => (int) $promotion->used_count,
            'once_per_customer' => (bool) $promotion->once_per_customer,
            // In words rather than as raw rules: a reader wants to know why a
            // sale it can see is not applying, and "Storefront — is one of
            // Wholesale" answers that where an array of ids does not.
            'applies_when' => array_map(
                fn (array $rule) => app(DisplayRules::class)->describe($rule),
                (array) ($promotion->rules ?? []),
            ),
            'tiers' => $promotion->tiers->map(fn (PromotionTier $tier) => [
                'position' => (int) $tier->position,
                'match' => $tier->matchAny() ? 'any' : 'all',
                'discount_type' => $tier->discount_type,
                'discount_value' => $tier->discount_value,
                // The same sentences the admin list prints. A caller reading
                // this to explain a discount to a human should not have to
                // reimplement the vocabulary to do it.
                'discount' => $this->describeDiscount($tier),
                'conditions' => Conditions::describe($tier->rules(), $promotion->zone, $conditionNames),
            ])->all(),
            'created_at' => $promotion->created_at?->toIso8601String(),
            'updated_at' => $promotion->updated_at?->toIso8601String(),
        ];
    }

    protected function describeDiscount(PromotionTier $tier): string
    {
        $value = (int) ($tier->discount_value ?? 0);

        return match ($tier->discount_type) {
            PromotionTier::PERCENT => $value.'%',
            PromotionTier::FIXED => admin_price($value),
            PromotionTier::TO_PERCENT => __('price becomes :percent% of it', ['percent' => $value]),
            PromotionTier::TO_AMOUNT => __('price becomes :price', ['price' => admin_price($value)]),
            PromotionTier::FREE_SHIPPING => __('free shipping'),
            PromotionTier::GIVE_GROUP => __('upgrade to :group', ['group' => '#'.$tier->give_group_id]),
            PromotionTier::FREE_PRODUCT => __('gift: :sku ×:qty', ['sku' => '#'.$tier->free_product_id, 'qty' => $tier->free_qty]),
            default => $tier->discount_type,
        };
    }
}

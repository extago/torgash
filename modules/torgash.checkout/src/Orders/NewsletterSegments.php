<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

/**
 * What the orders know about a subscriber, for the newsletter's campaigns
 * (`newsletter.segments`, PLAN §57.4): bought or not, how lately, where to.
 * An order is the subscriber's when it carries their address or their
 * account. An order that never became a sale — cancelled, declined, failed,
 * merged into another, or still waiting for its online payment — is not a
 * purchase.
 */
class NewsletterSegments
{
    public const NOT_A_SALE = ['cancelled', 'declined', 'failed', 'merged', 'awaiting_payment'];

    /** @return array<string, array<string, mixed>> */
    public static function kinds(): array
    {
        $days = ['key' => 'days', 'type' => 'number', 'label' => 'In the last … days', 'help' => 'Empty — ever.'];

        return [
            'bought' => [
                'label' => 'Bought',
                'fields' => [$days],
                'apply' => fn (Builder $q, array $p) => $q->whereExists(fn (Query $o) => self::orders($o, $p)),
            ],
            'not_bought' => [
                'label' => 'Did not buy',
                'fields' => [$days],
                'apply' => fn (Builder $q, array $p) => $q->whereNotExists(fn (Query $o) => self::orders($o, $p)),
            ],
            'city' => [
                'label' => 'Bought with delivery to a town',
                'fields' => [['key' => 'city', 'type' => 'text', 'label' => 'Town', 'help' => 'As written in the orders; several — separated by commas.']],
                'apply' => function (Builder $q, array $p) {
                    $towns = array_values(array_filter(array_map(fn ($t) => mb_strtolower(trim($t)), explode(',', (string) ($p['city'] ?? '')))));

                    return $towns === [] ? $q : $q->whereExists(fn (Query $o) => self::orders($o, [])
                        ->whereIn(\Illuminate\Support\Facades\DB::raw('lower(trim(m_checkout_orders.city))'), $towns));
                },
            ],
        ];
    }

    /** Orders of the subscriber on the outer query that were sales, within `days` when given. */
    protected static function orders(Query $o, array $p): Query
    {
        $days = (int) ($p['days'] ?? 0);

        return $o->selectRaw('1')->from('m_checkout_orders')
            ->where(fn (Query $mine) => $mine
                ->whereRaw('lower(m_checkout_orders.email) = m_newsletter_subscribers.email')
                ->orWhereColumn('m_checkout_orders.user_id', 'm_newsletter_subscribers.user_id'))
            ->whereNotIn('m_checkout_orders.status', self::NOT_A_SALE)
            ->whereNull('m_checkout_orders.deleted_at')
            ->when($days > 0, fn (Query $w) => $w->where('m_checkout_orders.created_at', '>=', now()->subDays($days)));
    }
}

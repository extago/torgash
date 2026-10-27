<?php

namespace Torgash\Shipping\Invariants;

use Illuminate\Support\Facades\DB;
use Torgash\Shipping\ShippingProviders;

/**
 * What must be true about delivery, said by the module that owns it.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the guard
 * being installed.
 *
 * All three are the same failure seen from three sides, and it is the most
 * expensive silent one a shop has: **the customer reaches the checkout and is
 * offered nothing.** Nobody complains — they leave — and the admin screens all
 * look right, because every part of the configuration is present and only the
 * JOIN between the parts is missing.
 *
 * What makes each of these a statement rather than a warning on a form is that
 * they are true of the shop as a whole. A method is edited on one screen and its
 * rates on another; a zone is edited on a third. Nothing anybody opens is in a
 * position to notice that the method they are looking at is offered in no zone
 * at all — that is exactly the question nobody is asked, which is what this
 * engine exists for.
 */
class ShippingInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            // The zone gate: ShippingOptions starts from ShippingRate, so a
            // method with no rate row anywhere is invisible at every address,
            // whatever its price mode says — a carrier method included, since
            // the rate is what PERMITS the method in a zone even when the
            // carrier is the one pricing it.
            'shipping.methods_can_be_offered' => [
                'label' => 'Every delivery method on offer is offered somewhere',
                'explain' => 'A method is offered in a zone only where it has a rate, so one with no rate in any enabled zone is on offer at no address at all — the customer reaches the checkout and finds it missing. Open the method and give it a rate in the zones it serves, or switch the method off so the shop stops counting on it.',
                'check' => fn (int $limit) => DB::table('m_shipping_methods as m')
                    ->where('m.enabled', true)
                    ->whereNotExists(fn ($q) => $q
                        ->from('m_shipping_rates as r')
                        ->join('m_shipping_zones as z', 'z.id', '=', 'r.zone_id')
                        ->whereColumn('r.shipping_method_id', 'm.id')
                        ->where('r.enabled', true)
                        ->where('z.enabled', true))
                    ->orderBy('m.id')
                    ->limit($limit)
                    ->get(['m.id', 'm.name', 'm.price_mode'])
                    ->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => $this->name($row->name, $row->id),
                        'detail' => ['No rate in any enabled zone.'],
                        'url' => route('admin.shipping.methods.edit', $row->id),
                    ]),
            ],

            // A zone is a set of rules, and a zone with none matches nothing —
            // ZoneMatcher only ever looks at `m_shipping_zone_rules`. The rates
            // written in it are not wrong, they are unreachable, which is why
            // this is a warning: the shop is not broken, its work is.
            'shipping.zones_cover_somewhere' => [
                'severity' => 'warning',
                'label' => 'Every enabled delivery zone covers somewhere',
                'explain' => 'A zone is the places it names, and this one names none — no address can ever match it, so every rate written in it is unreachable. Add a country, region, town or postcode to the zone, or delete it.',
                'check' => fn (int $limit) => DB::table('m_shipping_zones as z')
                    ->where('z.enabled', true)
                    ->whereNotExists(fn ($q) => $q
                        ->from('m_shipping_zone_rules as r')
                        ->whereColumn('r.zone_id', 'z.id'))
                    ->orderBy('z.id')
                    ->limit($limit)
                    ->get(['z.id', 'z.name'])
                    ->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'title' => $this->name($row->name, $row->id),
                        'detail' => [
                            'Covers nothing; :n rate(s) written in it.',
                            ['n' => DB::table('m_shipping_rates')->where('zone_id', $row->id)->count()],
                        ],
                        'url' => route('admin.shipping.zones.edit', $row->id),
                    ]),
            ],

            // A carrier is a registry entry (`shipping.providers`), so the key
            // stored on the method outlives the module that answered for it.
            // Disabling Nova Poshta leaves methods pointing at "novaposhta",
            // and what the customer then sees depends on `on_incalculable` —
            // which is a setting about a carrier that could not answer, not
            // about one that is not there at all.
            'shipping.carriers_are_present' => [
                'label' => 'Every method that names a carrier has that carrier installed',
                'explain' => 'The method names a carrier no module answers for any more — usually one that has been disabled or uninstalled. Its branch picker cannot be drawn and it cannot be priced, so what the customer is shown falls to the method\'s "when it cannot be priced" setting. Install the carrier\'s module again, or change the method to price itself from its rates.',
                'check' => function (int $limit) {
                    $known = array_keys(app(ShippingProviders::class)->all());

                    return DB::table('m_shipping_methods as m')
                        ->where('m.enabled', true)
                        ->whereNotNull('m.provider')
                        ->where('m.provider', '!=', '')
                        ->when($known !== [], fn ($q) => $q->whereNotIn('m.provider', $known))
                        ->orderBy('m.id')
                        ->limit($limit)
                        ->get(['m.id', 'm.name', 'm.provider'])
                        ->map(fn ($row) => [
                            'id' => (string) $row->id,
                            'title' => $this->name($row->name, $row->id),
                            'detail' => ['Names the carrier ":key", which nothing answers for.', ['key' => $row->provider]],
                            'url' => route('admin.shipping.methods.edit', $row->id),
                        ]);
                },
            ],
        ];
    }

    /**
     * A translated name read off a raw row.
     *
     * The checks are query-builder rows rather than models on purpose (this
     * runs over the whole shop on a timer), so the jsonb column arrives as a
     * string and there is no `t()` to call. Base language first, then whatever
     * is filled in — the same order HasTranslations reads in, without loading a
     * model to get it.
     */
    protected function name(?string $json, int $id): string
    {
        $names = array_filter((array) json_decode((string) $json, true), 'is_string');

        return $names[content_base_locale()] ?? (reset($names) ?: '#'.$id);
    }
}

<?php

namespace Torgash\Kits\Exim;

use Illuminate\Support\Carbon;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;

/**
 * Kits in a spreadsheet (PLAN §33.7) — the same shape, and for the same
 * reasons, as `Torgash\Options\Exim\OptionSchema`.
 *
 * Two entities, because a kit and its slots are two lists and exim's shape is
 * one model per row. Everything points by CODE or SKU, never by id, so a file
 * means the same thing in the shop it came from and the shop it is going to.
 * The discount is money and obeys the money rule: the file carries the TYPED
 * amount and its currency, and the books column is derived through `Prices`.
 *
 * `torgash.exim` is not in this module's requires and does not need to be:
 * these registrations only populate the hook bus, and nothing fires unless
 * exim itself asks.
 */
class KitSchema
{
    public function register(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + [
            'kit' => 'Kit',
            'kit_slot' => 'Kit slot',
        ]);

        hook_listen('exim.public_entities', fn (array $v) => [...$v, 'kit', 'kit_slot']);

        hook_listen('exim.query.kit', function ($v, array $ctx) {
            $query = Kit::query()->with('mainProduct');

            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->where('enabled', true);
            }

            return $query;
        });

        hook_listen('exim.query.kit_slot', function ($v, array $ctx) {
            $query = KitSlot::query()->with(['kit', 'product']);

            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->where('enabled', true);
            }

            return $query;
        });

        hook_listen('exim.match.kit', fn ($v, array $ctx) => $this->matchByCode(Kit::class, $ctx['row']['code'] ?? null));
        hook_listen('exim.match.kit_slot', fn ($v, array $ctx) => $this->matchByCode(KitSlot::class, $ctx['row']['code'] ?? null));

        hook_listen('exim.schema.kit', fn (array $fields) => [...$fields, ...$this->kitFields()]);
        hook_listen('exim.schema.kit_slot', fn (array $fields) => [...$fields, ...$this->slotFields()]);
    }

    /** @return list<array<string, mixed>> */
    protected function kitFields(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'get' => fn (Kit $k) => $k->code, 'set' => fn (Kit $k, $v) => $k->code = ($v ?: null)],
            ['key' => 'admin_name', 'label' => 'Admin name', 'get' => fn (Kit $k) => $k->admin_name, 'set' => fn (Kit $k, $v) => $k->admin_name = (string) $v],
            ['key' => 'name', 'label' => 'Name', 'translatable' => true, 'empty' => []],
            // Empty means "placed by display rules", which is a real answer
            // and not the same as an unknown SKU — so an unrecognised one
            // leaves the kit where it was.
            ['key' => 'main_product_sku', 'label' => 'Main product SKU', 'get' => fn (Kit $k) => $k->mainProduct?->sku, 'set' => function (Kit $k, $v) {
                $sku = trim((string) $v);

                if ($sku === '') {
                    $k->main_product_id = null;

                    return;
                }

                $k->main_product_id = Product::where('sku', $sku)->value('id') ?? $k->main_product_id;
            }],
            ['key' => 'placement', 'label' => 'Placement', 'get' => fn (Kit $k) => $k->placement, 'set' => fn (Kit $k, $v) => $k->placement = in_array($v, Kit::PLACEMENTS, true) ? $v : ($k->placement ?: 'after_buy_box')],
            // What the kit IS: several separate items, or one with the rest
            // inside it. A word the file does not recognise leaves it alone —
            // the two behave differently enough that guessing would change
            // what a shop sells.
            ['key' => 'type', 'label' => 'Type (simple/extended)', 'get' => fn (Kit $k) => $k->type, 'set' => fn (Kit $k, $v) => $k->type = in_array($v, Kit::TYPES, true) ? $v : ($k->type ?: 'simple')],
            ['key' => 'layout', 'label' => 'Layout (grid/scroller)', 'get' => fn (Kit $k) => $k->layout, 'set' => fn (Kit $k, $v) => $k->layout = in_array($v, Kit::LAYOUTS, true) ? $v : ($k->layout ?: 'grid')],
            ['key' => 'starts_at', 'label' => 'Starts at', 'get' => fn (Kit $k) => $k->starts_at?->toDateTimeString(), 'set' => fn (Kit $k, $v) => $k->starts_at = $this->date($v)],
            ['key' => 'ends_at', 'label' => 'Ends at', 'get' => fn (Kit $k) => $k->ends_at?->toDateTimeString(), 'set' => fn (Kit $k, $v) => $k->ends_at = $this->date($v)],
            ['key' => 'sort', 'label' => 'Sort', 'get' => fn (Kit $k) => $k->sort, 'set' => fn (Kit $k, $v) => $k->sort = (int) $v],
            ['key' => 'enabled', 'label' => 'Enabled', 'get' => fn (Kit $k) => $k->enabled, 'set' => fn (Kit $k, $v) => $k->enabled = $this->bool($v)],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function slotFields(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'get' => fn (KitSlot $s) => $s->code, 'set' => fn (KitSlot $s, $v) => $s->code = ($v ?: null)],
            // A slot with no kit is not a row anybody can place, so an unknown
            // code leaves an existing slot where it is rather than moving it
            // into the wrong set.
            ['key' => 'kit_code', 'label' => 'Kit code', 'get' => fn (KitSlot $s) => $s->kit?->code, 'set' => function (KitSlot $s, $v) {
                $id = Kit::where('code', trim((string) $v))->value('id');

                if ($id) {
                    $s->kit_id = $id;
                }
            }],
            ['key' => 'kind', 'label' => 'Kind', 'get' => fn (KitSlot $s) => $s->kind, 'set' => fn (KitSlot $s, $v) => $s->kind = in_array($v, KitSlot::KINDS, true) ? $v : ($s->kind ?: 'product')],
            ['key' => 'product_sku', 'label' => 'Product SKU', 'get' => fn (KitSlot $s) => $s->product?->sku, 'set' => function (KitSlot $s, $v) {
                $sku = trim((string) $v);
                $s->product_id = $sku === '' ? null : (Product::where('sku', $sku)->value('id') ?? $s->product_id);
            }],
            ['key' => 'source', 'label' => 'Source', 'get' => fn (KitSlot $s) => $s->source, 'set' => fn (KitSlot $s, $v) => $s->source = (trim((string) $v) ?: null)],
            // "The same value of this characteristic as the main product" —
            // compatibility with no table of pairs (§33.5), named by the
            // characteristic's own code.
            // Several, comma-separated, and ALL of them must match. A code
            // the shop does not have is DROPPED rather than silently widening
            // the slot: a condition nobody can answer would offer trousers of
            // every fabric under a heading promising one.
            ['key' => 'match_feature_codes', 'label' => 'Match characteristics (codes)', 'get' => fn (KitSlot $s) => implode(',', Feature::whereIn('id', $s->matchFeatureIds())->pluck('code')->all()), 'set' => function (KitSlot $s, $v) {
                $codes = array_filter(array_map('trim', explode(',', (string) $v)));

                $s->match_features = $codes === []
                    ? []
                    : Feature::whereIn('code', $codes)->pluck('id')->map('intval')->values()->all();
            }],
            ['key' => 'qty', 'label' => 'Quantity', 'get' => fn (KitSlot $s) => $s->qty, 'set' => fn (KitSlot $s, $v) => $s->qty = max(1, (int) $v)],
            ['key' => 'discount_type', 'label' => 'Discount type', 'get' => fn (KitSlot $s) => $s->discount_type, 'set' => fn (KitSlot $s, $v) => $s->discount_type = in_array($v, KitSlot::DISCOUNTS, true) ? $v : ($s->discount_type ?: 'percent')],
            ['key' => 'discount_percent', 'label' => 'Discount percent', 'get' => fn (KitSlot $s) => $s->discount_percent, 'set' => fn (KitSlot $s, $v) => $s->discount_percent = ($v === '' || $v === null ? null : (float) $v)],
            ['key' => 'discount_currency', 'label' => 'Discount currency', 'get' => fn (KitSlot $s) => $s->discount_currency, 'set' => fn (KitSlot $s, $v) => $s->discount_currency = (trim((string) $v) ?: null)],
            ['key' => 'discount_amount', 'label' => 'Discount amount (minor units)', 'get' => fn (KitSlot $s) => $s->discount_amount_original ?? $s->discount_amount, 'set' => function (KitSlot $s, $v) {
                // After `discount_currency`, for the reason every money pair
                // in an export schema is ordered that way.
                $money = app(Prices::class)->row(((int) $v) / 100, $s->discount_currency);

                $s->discount_amount = $money['price'];
                $s->discount_amount_original = $money['price_original'];
            }],
            ['key' => 'required', 'label' => 'Required', 'get' => fn (KitSlot $s) => $s->required, 'set' => fn (KitSlot $s, $v) => $s->required = $this->bool($v)],
            ['key' => 'swappable', 'label' => 'Swappable', 'get' => fn (KitSlot $s) => $s->swappable, 'set' => fn (KitSlot $s, $v) => $s->swappable = $this->bool($v)],
            ['key' => 'sort', 'label' => 'Sort', 'get' => fn (KitSlot $s) => $s->sort, 'set' => fn (KitSlot $s, $v) => $s->sort = (int) $v],
            ['key' => 'enabled', 'label' => 'Enabled', 'get' => fn (KitSlot $s) => $s->enabled, 'set' => fn (KitSlot $s, $v) => $s->enabled = $this->bool($v)],
        ];
    }

    /** @return array{model: object, matchedBy: ?string} */
    protected function matchByCode(string $class, ?string $code): array
    {
        $code = trim((string) $code);
        $existing = $code === '' ? null : $class::where('code', $code)->first();

        return ['model' => $existing ?? new $class, 'matchedBy' => $existing ? 'code' : null];
    }

    protected function bool(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on', 'да'], true);
    }

    /** An unreadable date leaves the dates alone rather than ending the kit today. */
    protected function date(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

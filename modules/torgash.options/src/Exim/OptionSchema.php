<?php

namespace Torgash\Options\Exim;

use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;

/**
 * The configurator in a spreadsheet (PLAN §33.7).
 *
 * Why it is worth having: a shop with two hundred products each carrying an
 * extended warranty changes its price in one file rather than two hundred
 * forms, and a catalogue arriving from another engine brings its fourteen options
 * with it instead of being retyped.
 *
 * **Two entities, not one with the answers folded into a cell.** A group and
 * its choices are two lists, and exim's shape is one model per row — which is
 * also what makes the file a spreadsheet somebody can actually edit rather
 * than a column of JSON. The order they are imported in is the order they are
 * listed in: a choice names its group by code, and a group that is not there
 * yet is a row that cannot be placed.
 *
 * **Everything points by CODE, never by id.** A product is named by its SKU, a
 * characteristic by its code, a group by its own — the words that mean the
 * same thing in the shop the file came from and the shop it is going to
 * (§58's rule about natural keys, applied to configuration rather than to
 * goods).
 *
 * **Money obeys the money rule.** What is exported and imported is the typed
 * amount and its currency; the books column is derived through `Prices`, which
 * is the only place that arithmetic lives. A file that carried the books
 * amount would silently rewrite it the first time it was imported into a shop
 * with a different base currency.
 */
class OptionSchema
{
    public function register(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + [
            'option_group' => 'Option group',
            'option_choice' => 'Option choice',
        ]);

        hook_listen('exim.public_entities', fn (array $v) => [...$v, 'option_group', 'option_choice']);

        hook_listen('exim.query.option_group', function ($v, array $ctx) {
            $query = OptionGroup::query()->with('product');

            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->where('enabled', true);
            }

            return $query;
        });

        hook_listen('exim.query.option_choice', function ($v, array $ctx) {
            $query = OptionChoice::query()->with(['group', 'product']);

            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->where('enabled', true);
            }

            return $query;
        });

        // One column, as `exim.match.*` is shaped. A row with no code is a row
        // this file cannot be about — better a refusal than attaching a
        // warranty to whatever happened to sort first.
        hook_listen('exim.match.option_group', fn ($v, array $ctx) => $this->matchByCode(
            OptionGroup::class, $ctx['row']['code'] ?? null,
        ));
        hook_listen('exim.match.option_choice', fn ($v, array $ctx) => $this->matchByCode(
            OptionChoice::class, $ctx['row']['code'] ?? null,
        ));

        hook_listen('exim.schema.option_group', fn (array $fields) => [...$fields, ...$this->groupFields()]);
        hook_listen('exim.schema.option_choice', fn (array $fields) => [...$fields, ...$this->choiceFields()]);
    }

    /** @return list<array<string, mixed>> */
    protected function groupFields(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'get' => fn (OptionGroup $g) => $g->code, 'set' => fn (OptionGroup $g, $v) => $g->code = ($v ?: null)],
            ['key' => 'admin_name', 'label' => 'Admin name', 'get' => fn (OptionGroup $g) => $g->admin_name, 'set' => fn (OptionGroup $g, $v) => $g->admin_name = (string) $v],
            // Which product asks it — by SKU, because an id means nothing in
            // the shop the file is going to. Empty is a SHARED group, which is
            // a different thing from "the product could not be found", so an
            // unknown SKU leaves the group where it was rather than quietly
            // making it shop-wide.
            ['key' => 'product_sku', 'label' => 'Product SKU', 'get' => fn (OptionGroup $g) => $g->product?->sku, 'set' => function (OptionGroup $g, $v) {
                $sku = trim((string) $v);

                if ($sku === '') {
                    $g->product_id = null;

                    return;
                }

                $g->product_id = Product::where('sku', $sku)->value('id') ?? $g->product_id;
            }],
            ['key' => 'name', 'label' => 'Name', 'translatable' => true, 'empty' => []],
            ['key' => 'hint', 'label' => 'Hint', 'translatable' => true, 'group' => 'texts'],
            // A type the engine does not know would make the question
            // undrawable, so an unrecognised word leaves it as it was.
            ['key' => 'type', 'label' => 'Type', 'get' => fn (OptionGroup $g) => $g->type, 'set' => fn (OptionGroup $g, $v) => $g->type = in_array($v, OptionGroup::TYPES, true) ? $v : ($g->type ?: 'select')],
            ['key' => 'required', 'label' => 'Required', 'get' => fn (OptionGroup $g) => $g->required, 'set' => fn (OptionGroup $g, $v) => $g->required = $this->bool($v)],
            ['key' => 'per_unit', 'label' => 'Per unit', 'get' => fn (OptionGroup $g) => $g->per_unit, 'set' => fn (OptionGroup $g, $v) => $g->per_unit = $this->bool($v)],
            ['key' => 'min_select', 'label' => 'Choose at least', 'get' => fn (OptionGroup $g) => $g->min_select, 'set' => fn (OptionGroup $g, $v) => $g->min_select = $this->intOrNull($v)],
            ['key' => 'max_select', 'label' => 'Choose at most', 'get' => fn (OptionGroup $g) => $g->max_select, 'set' => fn (OptionGroup $g, $v) => $g->max_select = $this->intOrNull($v)],
            ['key' => 'min_qty', 'label' => 'Quantity from', 'get' => fn (OptionGroup $g) => $g->min_qty, 'set' => fn (OptionGroup $g, $v) => $g->min_qty = max(1, (int) $v)],
            ['key' => 'max_qty', 'label' => 'Quantity up to', 'get' => fn (OptionGroup $g) => $g->max_qty, 'set' => fn (OptionGroup $g, $v) => $g->max_qty = $this->intOrNull($v)],
            ['key' => 'max_length', 'label' => 'Longest text', 'get' => fn (OptionGroup $g) => $g->max_length, 'set' => fn (OptionGroup $g, $v) => $g->max_length = $this->intOrNull($v)],
            ['key' => 'file_types', 'label' => 'File formats', 'get' => fn (OptionGroup $g) => $g->file_types, 'set' => fn (OptionGroup $g, $v) => $g->file_types = (trim((string) $v) ?: null)],
            ['key' => 'max_kb', 'label' => 'Largest file (KB)', 'get' => fn (OptionGroup $g) => $g->max_kb, 'set' => fn (OptionGroup $g, $v) => $g->max_kb = $this->intOrNull($v)],
            // The question this one waits for, by code — the same rule as
            // everything else here, and the reason a chain of dependencies
            // survives the trip.
            ['key' => 'depends_on', 'label' => 'Asked after (group code)', 'get' => fn (OptionGroup $g) => $g->depends_on_group_id
                ? OptionGroup::where('id', $g->depends_on_group_id)->value('code')
                : null, 'set' => function (OptionGroup $g, $v) {
                    $code = trim((string) $v);
                    $g->depends_on_group_id = $code === ''
                        ? null
                        : (OptionGroup::where('code', $code)->value('id') ?? $g->depends_on_group_id);
                }],
            ['key' => 'sort', 'label' => 'Sort', 'get' => fn (OptionGroup $g) => $g->sort, 'set' => fn (OptionGroup $g, $v) => $g->sort = (int) $v],
            ['key' => 'enabled', 'label' => 'Enabled', 'get' => fn (OptionGroup $g) => $g->enabled, 'set' => fn (OptionGroup $g, $v) => $g->enabled = $this->bool($v)],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function choiceFields(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'get' => fn (OptionChoice $c) => $c->code, 'set' => fn (OptionChoice $c, $v) => $c->code = ($v ?: null)],
            // The question this answer belongs to. An answer with no question
            // is not a row anybody can place, so an unknown code leaves an
            // existing choice where it is and a new one unsaved rather than
            // attaching it to the wrong group.
            ['key' => 'group_code', 'label' => 'Group code', 'get' => fn (OptionChoice $c) => $c->group?->code, 'set' => function (OptionChoice $c, $v) {
                $id = OptionGroup::where('code', trim((string) $v))->value('id');

                if ($id) {
                    $c->group_id = $id;
                }
            }],
            ['key' => 'kind', 'label' => 'Kind', 'get' => fn (OptionChoice $c) => $c->kind, 'set' => fn (OptionChoice $c, $v) => $c->kind = in_array($v, OptionChoice::KINDS, true) ? $v : ($c->kind ?: 'plain')],
            ['key' => 'name', 'label' => 'Name', 'translatable' => true],
            ['key' => 'product_sku', 'label' => 'Product SKU', 'get' => fn (OptionChoice $c) => $c->product?->sku, 'set' => function (OptionChoice $c, $v) {
                $sku = trim((string) $v);
                $c->product_id = $sku === '' ? null : (Product::where('sku', $sku)->value('id') ?? $c->product_id);
            }],
            ['key' => 'source', 'label' => 'Source', 'get' => fn (OptionChoice $c) => $c->source, 'set' => fn (OptionChoice $c, $v) => $c->source = (trim((string) $v) ?: null)],
            ['key' => 'price_rule', 'label' => 'Price rule', 'get' => fn (OptionChoice $c) => $c->price_rule, 'set' => fn (OptionChoice $c, $v) => $c->price_rule = (trim((string) $v) ?: ($c->price_rule ?: 'fixed'))],
            // The TYPED amount, in the currency it was typed in — the books
            // column beside it is derived and is not in the file at all. Read
            // as minor units, like every other money column in an export.
            ['key' => 'currency', 'label' => 'Amount currency', 'get' => fn (OptionChoice $c) => $c->currency, 'set' => fn (OptionChoice $c, $v) => $c->currency = (trim((string) $v) ?: null)],
            ['key' => 'amount', 'label' => 'Amount (minor units)', 'get' => fn (OptionChoice $c) => $c->amount_original ?? $c->amount, 'set' => function (OptionChoice $c, $v) {
                // Minor units in the file, like `base_price` in the product
                // schema: a spreadsheet travelling between shops must not
                // change meaning because one of them writes a decimal comma.
                // Must come AFTER `currency`, which is why it is listed after
                // it — the same ordering rule `price_currency`/`base_price`
                // already state.
                $money = app(Prices::class)->row(((int) $v) / 100, $c->currency);

                $c->amount = $money['price'];
                $c->amount_original = $money['price_original'];
            }],
            ['key' => 'percent', 'label' => 'Percent', 'get' => fn (OptionChoice $c) => $c->percent, 'set' => fn (OptionChoice $c, $v) => $c->percent = ($v === '' || $v === null ? null : (float) $v)],
            ['key' => 'weight_g', 'label' => 'Weight (g)', 'get' => fn (OptionChoice $c) => $c->weight, 'set' => fn (OptionChoice $c, $v) => $c->weight = $this->intOrNull($v)],
            ['key' => 'max_qty', 'label' => 'Quantity up to', 'get' => fn (OptionChoice $c) => $c->max_qty, 'set' => fn (OptionChoice $c, $v) => $c->max_qty = $this->intOrNull($v)],
            ['key' => 'is_default', 'label' => 'Chosen by default', 'get' => fn (OptionChoice $c) => $c->is_default, 'set' => fn (OptionChoice $c, $v) => $c->is_default = $this->bool($v)],
            ['key' => 'sort', 'label' => 'Sort', 'get' => fn (OptionChoice $c) => $c->sort, 'set' => fn (OptionChoice $c, $v) => $c->sort = (int) $v],
            ['key' => 'enabled', 'label' => 'Enabled', 'get' => fn (OptionChoice $c) => $c->enabled, 'set' => fn (OptionChoice $c, $v) => $c->enabled = $this->bool($v)],
        ];
    }

    /** @return array{model: object, matchedBy: ?string} */
    protected function matchByCode(string $class, ?string $code): array
    {
        $code = trim((string) $code);
        $existing = $code === '' ? null : $class::where('code', $code)->first();

        return ['model' => $existing ?? new $class, 'matchedBy' => $existing ? 'code' : null];
    }

    /**
     * A spreadsheet says yes in more ways than one, and an empty cell is
     * false rather than "leave it alone": a column that is IN the file is a
     * statement about every row of it.
     */
    protected function bool(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'on', 'да'], true);
    }

    protected function intOrNull(mixed $value): ?int
    {
        return ($value === '' || $value === null) ? null : (int) $value;
    }
}

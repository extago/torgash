<?php

namespace Torgash\Options;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Torgash\Cart\Lines;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Selections\Sources;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;

/**
 * The one place an answered configuration is read, checked and priced
 * (PLAN §33.4) — what `PlaceOrder` is for an order and `Submit` is for an
 * enquiry.
 *
 * Three jobs, deliberately in one class because they have to agree exactly:
 *
 * - `normalise()` turns what a form (or an API client) submitted into the one
 *   canonical spelling the cart hashes into a line key. Two customers who
 *   picked the same processor in a different order are buying the same server,
 *   and that is only true if one piece of code decides what "the same" is.
 * - `validate()` refuses what the shop is not selling. It runs on the way in
 *   AND at placement, because a cart can sit for a week: a choice can be
 *   switched off, a linked product can run out, a source can stop containing
 *   the product somebody picked out of it. A crafted POST is the other reason —
 *   without the source check, "pick anything from Processors" would buy any
 *   product in the shop at the processor's price rule.
 * - `parts()` states what the configuration adds to the line, in the shape
 *   `torgash.cart` understands and nothing more (`cart.line.parts`).
 *
 * Prices are measured against the product's own price for THIS visitor, once,
 * before any option is added — see `PriceRules`.
 */
class Configurator
{
    /**
     * This module's key inside a cart line's configuration bag. A bag rather
     * than one array because a line can be configured by more than one module
     * at once, and each may only read and write its own corner of it.
     */
    public const KEY = 'options';

    /** @var array<int, Product|null> */
    protected array $products = [];

    /** @var array<int, Product|null> the cheapest answer to a `source` choice */
    protected array $cheapest = [];

    public function __construct(protected Groups $groups, protected PriceRules $rules) {}

    /**
     * What was submitted, in one spelling.
     *
     * Accepts both the form's shape (a value per group, however the group is
     * asked) and the canonical shape it produces, so a line being edited can be
     * fed straight back in. Anything the product does not ask about is dropped
     * rather than refused: an old link with a group that has since been deleted
     * still buys the product.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function normalise(Product $product, array $submitted): array
    {
        // Only this module's own corner of the bag: a line's configuration may
        // carry another module's answers (a kit's, tomorrow), and rewriting the
        // whole of it would silently delete them.
        $answers = (array) ($submitted[self::KEY] ?? []);
        $config = [];

        foreach ($this->groups->for($product) as $group) {
            $answer = $answers[(string) $group->id] ?? $answers[$group->id] ?? null;

            if ($answer === null || $answer === '' || $answer === []) {
                continue;
            }

            $entries = $this->entries($group, $answer);

            if ($entries !== []) {
                $config[(string) $group->id] = $entries;
            }
        }

        // An answer to a question this build does not ask is not an answer.
        // The customer picked memory, then changed the processor to one with
        // it soldered on: the memory question is gone from the form, and
        // keeping what was said to it would charge for a part that is not in
        // the machine and does not appear anywhere on screen. Worked out from
        // the answers themselves, so it settles chains in one go.
        $asked = $this->groups->asked($product, $config)
            ->map(fn (OptionGroup $group) => (string) $group->id)
            ->all();

        $config = array_intersect_key($config, array_flip($asked));

        // Canonical: keys sorted, entries sorted, nothing empty — the cart
        // hashes this into the line's key. Whatever else the bag holds is
        // handed back untouched.
        $bag = $submitted;
        unset($bag[self::KEY]);

        if ($config !== []) {
            $bag[self::KEY] = $config;
        }

        return Lines::canonical($bag);
    }

    /**
     * Refuse a configuration the shop is not selling.
     *
     * @param  array<string, mixed>  $config  canonical, as normalise() answers
     *
     * @throws ValidationException
     */
    public function validate(Product $product, array $config): void
    {
        $answers = (array) ($config[self::KEY] ?? []);
        $errors = [];

        // Only the questions this build actually asks: a required group that
        // the customer was never shown — because the answer it hangs off was
        // not given — may not refuse the purchase.
        foreach ($this->groups->asked($product, $answers) as $group) {
            $entries = (array) ($answers[(string) $group->id] ?? []);
            $field = 'options.'.$group->id;
            $name = $group->t('name');

            if ($entries === []) {
                if ($group->required) {
                    $errors[$field][] = match (true) {
                        $group->isFile() => __('Attach a file for :group.', ['group' => $name]),
                        $group->isTyped() => __('Fill in :group.', ['group' => $name]),
                        default => __('Choose :group.', ['group' => $name]),
                    };
                }

                continue;
            }

            // An uploaded answer is checked as BYTES that must still be there:
            // the file was accepted or refused when it arrived (`Files`), and
            // the only thing that can have changed since is that it is gone —
            // a basket left for a month and swept, a row deleted. Saying so
            // here is what stops an order being placed for a layout nobody
            // has.
            if ($group->isFile()) {
                $file = app(Files::class)->find((string) ($entries[0]['f'] ?? ''));

                if (! $file || ! $file->exists()) {
                    $errors[$field][] = __('The file for :group is no longer there — please attach it again.', [
                        'group' => $name,
                    ]);
                }

                continue;
            }

            // A typed option is checked as writing, not as a chosen answer:
            // there is nothing to be "no longer offered", and the one thing the
            // shop can refuse is a text longer than what it can engrave.
            if ($group->isTyped()) {
                $text = (string) ($entries[0]['t'] ?? '');

                if ($group->max_length && mb_strlen($text) > $group->max_length) {
                    $errors[$field][] = __(':group takes at most :n characters.', [
                        'group' => $name,
                        'n' => $group->max_length,
                    ]);
                }

                continue;
            }

            if ($group->isSingle() && count($entries) > 1) {
                $errors[$field][] = __('Only one choice in :group.', ['group' => $name]);
            }

            if ($group->min_select && count($entries) < $group->min_select) {
                $errors[$field][] = __('Choose at least :n in :group.', ['n' => $group->min_select, 'group' => $name]);
            }

            if ($group->max_select && count($entries) > $group->max_select) {
                $errors[$field][] = __('Choose at most :n in :group.', ['n' => $group->max_select, 'group' => $name]);
            }

            foreach ($entries as $entry) {
                $choice = $group->choices->firstWhere('id', (int) ($entry['c'] ?? 0));

                if (! $choice || ! $choice->enabled) {
                    $errors[$field][] = __('That choice in :group is no longer offered.', ['group' => $name]);

                    continue;
                }

                $qty = (int) ($entry['q'] ?? 1);

                if ($qty < max(1, (int) $group->min_qty) || $qty > $group->qtyCeiling($choice)) {
                    $errors[$field][] = __('The quantity of :choice is out of range.', ['choice' => $choice->label()]);
                }

                $chosen = $this->productFor($choice, $entry);

                if ($choice->kind !== 'plain' && ! $chosen) {
                    $errors[$field][] = __(':choice is not available.', ['choice' => $choice->label()]);

                    continue;
                }

                if ($chosen && ! $chosen->isOrderable()) {
                    $errors[$field][] = __(':product cannot be bought right now.', ['product' => $chosen->t('name')]);
                }

                // A set is a set: the product has to still be IN it. Without
                // this the value is a product id a client can type.
                if ($choice->isSet() && $chosen && ! $this->inSource($choice, (int) $chosen->id)) {
                    $errors[$field][] = __('That choice in :group is no longer offered.', ['group' => $name]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * What this configuration adds to the line — the shape `torgash.cart`
     * reads (`Torgash\Cart\Lines`), and the only thing cart ever learns about
     * options.
     *
     * `$base` is the price every rule is measured against, and it is the
     * visitor's own unless a caller says otherwise — the one caller that does
     * is an answer written for NOBODY in particular (an export, a feed), where
     * the "visitor" would be whichever manager pressed the button, with their
     * own customer group's discount inside every percentage.
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public function parts(Product $product, int $qty, array $config, ?int $base = null): array
    {
        $answers = (array) ($config[self::KEY] ?? []);

        if ($answers === []) {
            return [];
        }

        // Once, before any option: the price every rule is measured against.
        $base ??= (int) $product->effectivePrice();
        $parts = [];

        // The asked questions, again: a line stored before a dependency was
        // written carries an answer to a question this build no longer asks,
        // and charging for it would put a part on the bill that the page in
        // front of the customer does not show.
        foreach ($this->groups->asked($product, $answers) as $group) {
            foreach ((array) ($answers[(string) $group->id] ?? []) as $entry) {
                $choice = $group->choices->firstWhere('id', (int) ($entry['c'] ?? 0));

                // What the customer sent, named as they named it: the workshop
                // reads "визитка-final-2.pdf", and the token means nothing to
                // anybody. A file whose row has gone says so rather than
                // printing an empty line — the order still holds what was
                // bought, and somebody has to notice.
                if ($group->isFile()) {
                    $file = app(Files::class)->find((string) ($entry['f'] ?? ''));
                    $name = $file?->label() ?? __('the file is no longer there');

                    $parts[] = [
                        'code' => 'g'.$group->id.'file'.($choice->id ?? 0),
                        'group' => $group->t('name'),
                        'label' => $group->t('name').': '.$name,
                        'qty' => 1,
                        'per_unit' => (bool) $group->per_unit,
                        'amount' => $choice && $choice->enabled
                            ? $this->rules->apply($choice, $base, null)
                            : 0,
                        'product_id' => null,
                        'sku' => null,
                        'name' => $name,
                        'weight' => 0,
                        // Where it can actually be fetched — never a path on
                        // the disk. SIGNED, because the two readers of a cart
                        // line are the customer who uploaded the file and the
                        // manager handling the order, and only the second of
                        // them is signed in. Registered outside
                        // `StorefrontRoutes`, so the signature covers one exact
                        // address and the language switcher cannot invalidate
                        // it (the trap `OrderKey`'s docblock names).
                        'url' => $file ? URL::signedRoute('options.file', ['file' => $file->token]) : null,
                        'image' => null,
                    ];

                    continue;
                }

                if ($group->isTyped() && ! $choice) {
                    // A free typed option: the words are part of the purchase,
                    // they simply cost nothing.
                    $parts[] = [
                        'code' => 'g'.$group->id.'text',
                        'group' => $group->t('name'),
                        'label' => $group->t('name').': '.(string) ($entry['t'] ?? ''),
                        'qty' => 1,
                        'per_unit' => (bool) $group->per_unit,
                        'amount' => 0,
                        'product_id' => null,
                        'sku' => null,
                        'name' => (string) ($entry['t'] ?? ''),
                        'weight' => 0,
                        'url' => null,
                        'image' => null,
                    ];

                    continue;
                }

                if (! $choice || ! $choice->enabled) {
                    continue;
                }

                $chosen = $this->productFor($choice, $entry);
                $label = $choice->label($chosen);

                // What the customer wrote IS the answer, so it is what the
                // cart, the order and the workshop read — the choice behind it
                // only says what it costs.
                if ($group->isTyped()) {
                    $label = (string) ($entry['t'] ?? $label);
                }

                $parts[] = [
                    'code' => 'g'.$group->id.'c'.$choice->id.($chosen ? 'p'.$chosen->id : ''),
                    'label' => $group->t('name').': '.$label,
                    'group' => $group->t('name'),
                    'qty' => max(1, (int) ($entry['q'] ?? 1)),
                    'per_unit' => (bool) $group->per_unit,
                    'amount' => $this->rules->apply($choice, $base, $chosen),
                    'product_id' => $chosen?->id,
                    'sku' => $chosen?->sku,
                    'name' => $chosen ? $chosen->t('name') : $label,
                    // A product's own weight (a characteristic may answer for
                    // it), or what the shop typed for a plain choice.
                    'weight' => $chosen ? (int) ($chosen->resolvedWeight() ?? 0) : (int) ($choice->weight ?? 0),
                    'url' => $chosen ? route('catalog.product', $chosen->slug) : null,
                    'image' => $chosen?->primaryImageUrl(120),
                ];
            }
        }

        return $parts;
    }

    /**
     * The products a `source` choice offers, narrowed by a search term when
     * there are too many to list — the owner's own note about the dropdown
     * needing a search field.
     *
     * @return Collection<int, Product>
     */
    public function setProducts(OptionChoice $choice, string $search = '', int $limit = 30): Collection
    {
        $query = $this->sourceQuery($choice);

        if (! $query) {
            return collect();
        }

        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('sku', 'ilike', '%'.$search.'%')
                ->orWhereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', ['%'.$search.'%']));
        }

        return $query->withCardData()->orderBy('m_catalog_products.id')->limit($limit)->get();
    }

    /**
     * The cheapest thing this `source` choice can be answered with — what a
     * price "from" is made of (PLAN §33.7).
     *
     * Cheapest by the `price` COLUMN, deliberately, and then priced properly
     * through the rule: the alternative is loading a category of processors to
     * ask each one for its `effectivePrice()`, which is the N+1 this engine
     * refuses everywhere else, and the catalogue's own "Cheapest first" already
     * answers the same question with the same column. Where a promotion makes
     * a dearer row the cheaper one, the "from" comes out slightly HIGH — the
     * safe direction: a shop that under-promises is one a customer is never
     * disappointed by, and a feed quoting less than the page can be bought for
     * is the one a marketplace penalises.
     *
     * Memoised per choice, because the answer does not depend on which product
     * is asking: a grid of twelve servers offering the same processors asks
     * once.
     */
    public function cheapestInSource(OptionChoice $choice): ?Product
    {
        $id = (int) $choice->id;

        if (array_key_exists($id, $this->cheapest)) {
            return $this->cheapest[$id];
        }

        $query = $this->sourceQuery($choice);

        if (! $query) {
            return $this->cheapest[$id] = null;
        }

        // Only what can actually be bought: a part that is out of stock is one
        // `validate()` refuses, so quoting the shop's price from it would be
        // quoting a build nobody can order.
        app(AvailabilityStatuses::class)->applyOrderable($query);

        return $this->cheapest[$id] = $query->withCardData()
            ->orderBy('price')
            ->orderBy('m_catalog_products.id')
            ->first();
    }

    /**
     * The product an answer is about: the choice's own for a `product` choice,
     * the one the customer picked for a `source` choice, nothing for a service.
     *
     * @param  array<string, mixed>  $entry
     */
    public function productFor(OptionChoice $choice, array $entry): ?Product
    {
        if ($choice->kind === 'product') {
            // Through `reachable()`, not the relation: a product that has been
            // disabled or deleted since the group was written is not an
            // answer any more, and loading it by id would offer it anyway.
            return $choice->product_id ? $this->product((int) $choice->product_id) : null;
        }

        if (! $choice->isSet()) {
            return null;
        }

        $id = (int) ($entry['p'] ?? 0);

        return $id > 0 ? $this->product($id) : null;
    }

    /**
     * A product that may be sold as part of something, memoised: a page with
     * five groups asks about the same processor for the form, for the price and
     * again for every answer submitted.
     */
    protected function product(int $id): ?Product
    {
        return $this->products[$id] ??= Product::reachable()->withCardData()->find($id);
    }

    /**
     * One group's answer, whatever shape it arrived in.
     *
     * A value is `<choiceId>` or `<choiceId>:<productId>` — one spelling for
     * every kind of control, which is what lets a `source` choice sit in a
     * dropdown beside a plain one.
     *
     * @return list<array<string, int>>
     */
    protected function entries(OptionGroup $group, mixed $answer): array
    {
        $entries = [];

        // An option the customer UPLOADS: one entry, carrying the TOKEN of the
        // file — never the bytes and never the name they gave it. The bytes
        // are on a private disk by then (`Files`), and the name is on the row,
        // because the line's key is a hash of this and renaming a file must
        // not make it a different purchase.
        if ($group->isFile()) {
            $token = is_array($answer)
                ? (string) ($answer['token'] ?? ($answer[0]['f'] ?? ''))
                : (string) $answer;

            if ($token === '') {
                return [];
            }

            $choice = $group->priceChoice();

            return [array_filter([
                'c' => (int) ($choice->id ?? 0),
                'q' => 1,
                'f' => $token,
            ], fn ($value) => $value !== '' && $value !== null)];
        }

        // An option the customer WRITES: one entry, carrying the text. What it
        // costs is the group's first choice, so the text rides on that choice
        // exactly as a quantity would.
        if ($group->isTyped()) {
            $text = is_array($answer)
                ? (string) ($answer['text'] ?? ($answer[0]['t'] ?? ''))
                : (string) $answer;

            // Control characters are not writing; a newline is, but only where
            // a paragraph was asked for.
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', trim($text));

            if ($group->type === 'text') {
                $text = preg_replace('/\s*\R\s*/u', ' ', $text);
            }

            if ($text === '') {
                return [];
            }

            $choice = $group->priceChoice();

            return [array_filter([
                // A free typed option has nothing to charge and therefore no
                // choice: `c` is 0 and the part is worth nothing, which is the
                // honest answer rather than inventing a price row.
                'c' => (int) ($choice->id ?? 0),
                'q' => 1,
                't' => $text,
            ], fn ($value) => $value !== '' && $value !== null)];
        }

        // Already canonical (a line being re-added, an API client speaking the
        // stored shape).
        if (is_array($answer) && isset($answer[0]) && is_array($answer[0]) && array_key_exists('c', $answer[0])) {
            foreach ($answer as $entry) {
                $entries[] = $this->entry(
                    (string) ($entry['c'] ?? '').(isset($entry['p']) ? ':'.$entry['p'] : ''),
                    (int) ($entry['q'] ?? 1),
                );
            }

            return $this->sorted($group, array_filter($entries));
        }

        if ($group->type === 'rows' && is_array($answer)) {
            foreach ($answer as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $entries[] = $this->entry((string) ($row['choice'] ?? ''), (int) ($row['qty'] ?? 1));
            }

            return $this->sorted($group, array_filter($entries));
        }

        if ($group->type === 'quantities' && is_array($answer)) {
            foreach ($answer as $value => $qty) {
                if ((int) $qty > 0) {
                    $entries[] = $this->entry((string) $value, (int) $qty);
                }
            }

            return $this->sorted($group, array_filter($entries));
        }

        foreach ((array) $answer as $value) {
            if (is_scalar($value)) {
                $entries[] = $this->entry((string) $value, 1);
            }
        }

        $entries = array_filter($entries);

        // One of means one: a form that somehow submitted two takes the first,
        // and validate() is what tells a client it did so.
        return $this->sorted($group, $group->isSingle() ? array_slice($entries, 0, 1) : $entries);
    }

    /** @return array<string, int>|null */
    protected function entry(string $value, int $qty): ?array
    {
        [$choiceId, $productId] = array_pad(explode(':', $value, 2), 2, null);
        $choiceId = (int) $choiceId;

        if ($choiceId <= 0) {
            return null;
        }

        $entry = ['c' => $choiceId, 'q' => max(1, $qty)];

        if ((int) $productId > 0) {
            $entry['p'] = (int) $productId;
        }

        return $entry;
    }

    /**
     * Entries in one order, and one entry per answer: picking the same
     * processor twice in two rows is four processors in one row, which is the
     * same sale and therefore the same line.
     *
     * @param  list<array<string, int>>  $entries
     * @return list<array<string, int>>
     */
    protected function sorted(OptionGroup $group, array $entries): array
    {
        $merged = [];

        foreach ($entries as $entry) {
            $key = $entry['c'].':'.($entry['p'] ?? 0);

            if (isset($merged[$key])) {
                $merged[$key]['q'] = min($group->qtyCeiling(), $merged[$key]['q'] + $entry['q']);

                continue;
            }

            $merged[$key] = $entry;
        }

        ksort($merged, SORT_NATURAL);

        return array_values($merged);
    }

    /** Is this product still in the set the choice offers? */
    protected function inSource(OptionChoice $choice, int $productId): bool
    {
        $query = $this->sourceQuery($choice);

        return $query ? $query->whereKey($productId)->exists() : false;
    }

    /**
     * The set a `source` choice stands for, as a query — the catalogue's own
     * registry answers it, so a category means its subtree and a filter means
     * whatever that module says it means. Starts at `listed()`, like every
     * other listing: what a visitor may not see is not offered as a part.
     */
    protected function sourceQuery(OptionChoice $choice): mixed
    {
        $resolved = app(Sources::class)->resolve((string) $choice->source);

        if (! $resolved) {
            return null;
        }

        [$definition, $arg] = $resolved;

        return ($definition['apply'])(Product::listed(), $arg, []);
    }
}

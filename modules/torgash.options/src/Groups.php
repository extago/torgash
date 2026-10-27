<?php

namespace Torgash\Options;

use App\Core\Display\DisplayRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Options\Models\OptionGroup;

/**
 * Which questions a given product asks (PLAN §33.4).
 *
 * Two sources, and the difference is who decided: a group that belongs to the
 * product, and a SHARED group placed by core's display rules — "everything in
 * Air conditioners", "anything with this characteristic". Rules rather than a
 * link table because a shop writes "extended warranty on every phone" once and
 * expects tomorrow's phone to be covered; a link table would need re-linking,
 * and nobody would remember.
 *
 * A product can still refuse a shared group (`m_options_group_exclusions`) —
 * one product in the category that genuinely has no such option. What it
 * cannot do is re-price it: that is §33.8's decision, and the reason a formula
 * is the only way a shared group prices itself.
 *
 * A registered per-request singleton, the rule every small hot lookup here
 * follows: a product page asks once for the form, once for the price and once
 * more per submitted answer.
 */
class Groups
{
    /** @var array<int, Collection<int, OptionGroup>> */
    protected array $memo = [];

    /** @var array<int, Collection<int, OptionGroup>> */
    protected array $own = [];

    /** @var Collection<int, OptionGroup>|null */
    protected ?Collection $shared = null;

    /** @var array<int, list<int>>|null */
    protected ?array $exclusions = null;

    /**
     * Every group this product asks, in the order it asks them.
     *
     * @return Collection<int, OptionGroup>
     */
    public function for(Product $product): Collection
    {
        $id = (int) $product->id;

        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        $own = $this->ownFor($id);
        $excluded = $this->exclusionsFor($id);

        $shared = $this->sharedGroups()
            ->reject(fn (OptionGroup $group) => in_array((int) $group->id, $excluded, true))
            // Core's engine, core's fail-closed rules: a rule kind whose module
            // is gone hides the group rather than being ignored, and a rule
            // whose context is missing answers No.
            ->filter(fn (OptionGroup $group) => app(DisplayRules::class)->passes(
                (array) $group->rules,
                ['product' => $product],
                (string) $group->rules_match,
            ));

        return $this->memo[$id] = $own->concat($shared)
            ->sortBy([['sort', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * The questions this product asks GIVEN what has been answered so far —
     * the one answer to "is this question on screen", and therefore the one
     * answer to "may it be demanded, stored and charged for" (PLAN §33.7).
     *
     * "Memory only with a processor" is a statement about a form being filled
     * in, so it cannot be settled once when the page is built: the customer
     * changes the processor to one with soldered memory and the memory
     * question has to go, taking the answer that was already given to it with
     * it. Hence one method asked by every stage — the form drawing itself,
     * `normalise()` deciding what is kept, `validate()` deciding what may be
     * demanded, `parts()` deciding what is charged — because a question nobody
     * was asked must not appear on the bill, and a question nobody can see
     * must not be able to block the Buy button.
     *
     * Resolved in two steps. First a CIRCLE is taken out — A asked only with B
     * and B only with A, a corrupt pair rather than an impossible one. It has
     * to be named explicitly rather than left to the second step, because a
     * circle in which both happen to hold an answer is perfectly stable and
     * would sail through: nobody could ever have reached that state on a page
     * (neither question is ever drawn), so the answers can only have come from
     * a crafted POST. The walk carries a seen-set — the restraint
     * `CategoryTree` already lives by, for the same reason: a walk that trusts
     * the data hangs the request.
     *
     * Then the answers, by repeated passes, hiding only — so it settles in at
     * most one pass per group, and every unresolved case fails closed like
     * every other rule in this engine.
     *
     * @param  array<string, mixed>  $answers  this module's corner of the bag
     * @return Collection<int, OptionGroup>
     */
    public function asked(Product $product, array $answers): Collection
    {
        $groups = $this->for($product);

        if (! $groups->contains(fn (OptionGroup $group) => $group->isDependent())) {
            return $groups;
        }

        $visible = $groups->mapWithKeys(fn (OptionGroup $group) => [(int) $group->id => true])->all();
        $dependency = $groups->mapWithKeys(
            fn (OptionGroup $group) => [(int) $group->id => (int) $group->depends_on_group_id],
        )->all();

        foreach ($groups as $group) {
            $seen = [];
            $cursor = (int) $group->id;

            while ($cursor > 0 && ! isset($seen[$cursor])) {
                $seen[$cursor] = true;
                $cursor = $dependency[$cursor] ?? 0;
            }

            // The walk came back to something it had already passed: this
            // question waits, however indirectly, on itself.
            if ($cursor > 0) {
                $visible[(int) $group->id] = false;
            }
        }

        for ($pass = 0; $pass < $groups->count(); $pass++) {
            $changed = false;

            foreach ($groups as $group) {
                if (! $visible[(int) $group->id] || ! $group->isDependent()) {
                    continue;
                }

                $on = (int) $group->depends_on_group_id;

                // A dependency on a question this product does not ask (a
                // shared group excluded here, a group belonging to another
                // product) hides it: the condition can never be met, and
                // drawing a question whose premise is absent is how a required
                // group makes a product unbuyable.
                $met = ($visible[$on] ?? false)
                    && $group->satisfiedBy((array) ($answers[(string) $on] ?? []));

                if (! $met) {
                    $visible[(int) $group->id] = false;
                    $changed = true;
                }
            }

            if (! $changed) {
                break;
            }
        }

        return $groups->filter(fn (OptionGroup $group) => $visible[(int) $group->id])->values();
    }

    /**
     * Does any question this product asks hang off another one?
     *
     * Asked by the page to decide whether answering something has to redraw
     * the form or only the total — and by the endpoint that answers it, which
     * is why it is one method rather than the same condition written twice.
     */
    public function hasDependencies(Product $product): bool
    {
        return $this->for($product)->contains(fn (OptionGroup $group) => $group->isDependent());
    }

    /** Does this product ask anything at all — what a card and a buy button need to know. */
    public function has(Product $product): bool
    {
        return $this->for($product)->isNotEmpty();
    }

    /**
     * …and does it REFUSE to be bought without an answer.
     *
     * Measured against the questions asked of an EMPTY form, which is what the
     * visitor meets: a required question that only appears once something else
     * is chosen does not stop anybody at the door.
     */
    public function demandsAnswers(Product $product): bool
    {
        return $this->asked($product, [])->contains(fn (OptionGroup $group) => $group->required);
    }

    /**
     * The questions of a whole PAGE of products, in one query.
     *
     * The rule every per-product lookup in this engine lives by: a card that
     * asks what a product costs to actually buy asks this, and a grid of
     * twelve would otherwise ask twelve times. Answered off
     * `catalog.products.listed`, which hands over the set before the first
     * card is drawn — the shared groups and the exclusions were already read
     * once per request, so this is the last of the three.
     *
     * @param  iterable<Product>  $products
     */
    public function prime(iterable $products): void
    {
        $ids = collect($products)
            ->map(fn ($product) => (int) ($product->id ?? 0))
            ->filter(fn (int $id) => $id > 0 && ! isset($this->own[$id]))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $groups = OptionGroup::with('choices')
            ->whereIn('product_id', $ids->all())
            ->where('enabled', true)
            ->get()
            ->groupBy('product_id');

        foreach ($ids as $id) {
            $this->own[$id] = $groups->get($id, collect())->values();
        }
    }

    /**
     * The groups that belong to this product, from the page's own set when it
     * announced itself and otherwise on its own.
     *
     * @return Collection<int, OptionGroup>
     */
    protected function ownFor(int $id): Collection
    {
        if ($id <= 0) {
            return collect();
        }

        return $this->own[$id] ??= OptionGroup::with('choices')
            ->where('product_id', $id)
            ->where('enabled', true)
            ->get();
    }

    /**
     * The shared groups, read once per request however many products a page
     * shows — a listing of twelve products would otherwise ask twelve times.
     *
     * @return Collection<int, OptionGroup>
     */
    protected function sharedGroups(): Collection
    {
        return $this->shared ??= OptionGroup::with('choices')
            ->whereNull('product_id')
            ->where('enabled', true)
            ->get();
    }

    /** @return list<int> */
    protected function exclusionsFor(int $productId): array
    {
        $this->exclusions ??= DB::table('m_options_group_exclusions')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('group_id')->map('intval')->all())
            ->all();

        return $this->exclusions[$productId] ?? [];
    }

    /** For tests and for a screen that has just saved. */
    public function flush(): void
    {
        $this->memo = [];
        $this->own = [];
        $this->shared = null;
        $this->exclusions = null;
    }
}

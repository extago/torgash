<?php

namespace Torgash\Options\Invariants;

use Torgash\Catalog\Models\Product;
use Torgash\Options\Configurator;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;

/**
 * What must be true about the questions a product asks, said by the module
 * that owns them.
 *
 * **The same silence as a kit's, and a worse consequence.** An answer whose
 * product has been disabled is dropped from the control and nothing says so
 * (`views/storefront/fields.blade.php` — the `@continue`), which is right: an
 * answer nobody can buy is not an answer. But a question is dropped the same
 * way when EVERY answer goes, and a REQUIRED question that is not on the page
 * is still checked when the customer presses Buy — so the product cannot be
 * bought at all, and what they see is "Choose Processor" about a control that
 * is not there. A kit that breaks loses an upsell; a configurator that breaks
 * loses the product.
 *
 * Which is the answer to "does a kit's invariant apply to an option's linked
 * product too": yes, and this is the more urgent half.
 *
 * Nothing here is a second opinion about availability — both statements ask
 * `Configurator`, the one class that decides what an answer is, exactly as the
 * form and the validation do. A check with its own idea of "offerable" would
 * eventually disagree with the page, and then the screen would be reporting
 * its own bug.
 *
 * Bounded by GROUPS looked at, each costing at most a query per choice. Groups
 * are written by hand; there are tens of them.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the
 * guard being installed.
 */
class OptionInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'options.required_questions_can_be_answered' => [
                'label' => 'Every required question has an answer that can be bought',
                'explain' => 'Every answer to this question is a product that cannot be sold right now, so the question is not drawn at all — and because it is required, the products that ask it cannot be bought: the customer presses Buy and is told to choose something that is not on the page. Restock one of the answers, switch the question off, or make it optional.',
                'check' => fn (int $limit) => $this->unanswerableGroups($limit),
            ],

            'options.answers_stand_for_something' => [
                'label' => 'Every answer that is a product has a product behind it',
                'explain' => 'The answer is switched on but never offered: the product it names is disabled or deleted, or the set it draws from no longer holds anything that can be bought. Nothing on the page says so — the answer is simply missing from the list. Point it at something else or switch it off, so the form on screen and the form in the admin say the same thing.',
                'severity' => 'warning',
                'check' => fn (int $limit) => $this->deadChoices($limit),
            ],
        ];
    }

    /**
     * Required questions with no buyable answer left.
     *
     * A typed or uploaded question is never one of these: it has no answers to
     * lose — what it asks for is writing or a file, and the customer supplies
     * it. A question with a `plain` answer (a service, priced by a formula) is
     * never one either: a formula cannot run out.
     *
     * @return list<array<string, mixed>>
     */
    protected function unanswerableGroups(int $limit): array
    {
        $configurator = app(Configurator::class);
        $found = [];

        foreach ($this->groups($limit) as $group) {
            if (! $group->required || $group->isTyped() || $group->isFile()) {
                continue;
            }

            $enabled = $group->choices->filter(fn (OptionChoice $choice) => (bool) $choice->enabled);

            // A question with no answers at all is a half-written one, not a
            // broken one: it was never offering anything, and the shop is in
            // the middle of writing it.
            if ($enabled->isEmpty()) {
                continue;
            }

            if ($enabled->contains(fn (OptionChoice $choice) => $this->isOffered($configurator, $choice))) {
                continue;
            }

            $found[] = [
                'id' => 'group'.$group->id,
                'title' => $group->t('name'),
                'detail' => $this->whoAsks($group),
                'url' => route('admin.options.groups.edit', $group->id),
            ];

            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * Answers that are a product and never reach the page.
     *
     * One finding per ANSWER, because that is what gets fixed — and only for a
     * question that is switched on: a group the shop has parked is not making
     * a promise to anybody.
     *
     * @return list<array<string, mixed>>
     */
    protected function deadChoices(int $limit): array
    {
        $configurator = app(Configurator::class);
        $found = [];

        foreach ($this->groups($limit) as $group) {
            foreach ($group->choices as $choice) {
                if (! $choice->enabled || $choice->kind === 'plain') {
                    continue;
                }

                if ($this->isOffered($configurator, $choice)) {
                    continue;
                }

                $found[] = [
                    'id' => 'choice'.$choice->id,
                    'title' => $group->t('name').': '.$choice->label(),
                    'detail' => $choice->isSet()
                        ? ['Nothing in the set it draws from can be bought.', []]
                        : ['The product it names is disabled, deleted or cannot be bought right now.', []],
                    'url' => route('admin.options.groups.edit', $group->id),
                ];

                if (count($found) >= $limit) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * Can this answer be offered to anybody at all?
     *
     * The same two questions the form asks while drawing the control, in the
     * same order: a `product` answer needs its product to be reachable and
     * orderable, a `source` answer needs one buyable product left in the set —
     * which is exactly what `cheapestInSource()` looks for, so the "from"
     * price and this statement can never disagree about whether the set is
     * empty.
     */
    protected function isOffered(Configurator $configurator, OptionChoice $choice): bool
    {
        if ($choice->kind === 'plain') {
            return true;
        }

        if ($choice->isSet()) {
            return $configurator->cheapestInSource($choice) !== null;
        }

        $product = $configurator->productFor($choice, []);

        return $product && $product->isOrderable();
    }

    /**
     * Whose page this question is on — the product it belongs to, or the fact
     * that it is placed by conditions.
     *
     * Named rather than counted: a shared group's conditions are read against
     * a product, and counting how many products a rule lands on means walking
     * the catalogue, which is not what a nightly statement about tens of rows
     * should cost.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function whoAsks(OptionGroup $group): array
    {
        if (! $group->product_id) {
            return ['Asked on every product its conditions place it on.', []];
        }

        $product = Product::query()->find($group->product_id);

        return $product
            ? ['Asked on ":product", which cannot be bought while this stands.', ['product' => $product->t('name') ?: $product->sku]]
            : ['Asked on a product that no longer exists.', []];
    }

    /**
     * The questions that are switched on, in the order a shop reads them.
     *
     * Not `Groups::for()`: that one answers "which questions does THIS product
     * ask", and what is checked here is true of the question itself, whatever
     * page it lands on.
     *
     * @return \Illuminate\Support\Collection<int, OptionGroup>
     */
    protected function groups(int $limit): \Illuminate\Support\Collection
    {
        return OptionGroup::with('choices')
            ->where('enabled', true)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}

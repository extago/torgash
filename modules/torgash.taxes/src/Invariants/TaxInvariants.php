<?php

namespace Torgash\Taxes\Invariants;

use Torgash\Taxes\Models\Tax;

/**
 * What must be true about charging tax, said by the module that charges it.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the guard
 * being installed.
 */
class TaxInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            // "Charged only in the places listed" with no place listed where it
            // IS charged is a tax charged nowhere — and the form saved it
            // without a word, because every half of it is valid on its own.
            // Rows saying "not charged here" do not count: they are exceptions
            // to a rate that, under `only_listed`, does not exist.
            'taxes.charged_somewhere' => [
                'label' => 'Every enabled tax is charged somewhere',
                'explain' => 'The tax is set to be charged only in the places listed, and no place is listed at a rate — so no order is ever charged it, while the tax still looks switched on. List the places where it is owed, or untick "Charged only in the places listed".',
                'check' => fn (int $limit) => Tax::query()
                    ->where('enabled', true)
                    ->where('only_listed', true)
                    ->whereDoesntHave('rates', fn ($q) => $q->where('charged', true))
                    ->orderBy('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Tax $tax) => [
                        'id' => (string) $tax->id,
                        'title' => $tax->t('name') ?: $tax->code,
                        'detail' => ['Charged only where listed, and nothing is listed.', []],
                        'url' => route('admin.taxes.edit', $tax),
                    ]),
            ],
        ];
    }
}

<?php

namespace Torgash\Promotions\Invariants;

use Torgash\Promotions\Models\Promotion;

/**
 * What must be true about a promotion, said by the module that runs them.
 *
 * Answered lazily through `guard.invariants`.
 *
 * This is the one the whole third part of the guard was argued from: the
 * promotions review of 2026-09-07 found counters past their limits, and the
 * data had been saying so for as long as it had been wrong.
 */
class PromotionInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'promotions.usage_within_limit' => [
                'label' => 'No promotion has been applied more times than it allows',
                'explain' => 'The counter is past the limit, so the promotion kept giving after it should have stopped — most often two orders placed in the same instant. Raise the limit if the extra ones are fine, or switch the promotion off.',
                'severity' => 'warning',
                'check' => fn (int $limit) => Promotion::query()
                    ->where('usage_limit', '>', 0)
                    ->whereColumn('used_count', '>', 'usage_limit')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Promotion $p) => [
                        'id' => (string) $p->id,
                        'title' => $p->t('name'),
                        'detail' => ['Applied :used of :limit', ['used' => $p->used_count, 'limit' => $p->usage_limit]],
                        'url' => route('admin.promotions.edit', $p->id),
                    ]),
            ],
        ];
    }
}

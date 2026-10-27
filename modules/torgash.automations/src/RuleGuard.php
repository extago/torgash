<?php

namespace Torgash\Automations;

/**
 * A rule's own changes do not trigger rules.
 *
 * Internal actions edit the order, and editing the order announces
 * `checkout.order.changed` — which is what triggers rules. Left alone, "when
 * the status changes, set the status" is an infinite loop, and two rules
 * pointing at each other is the same loop with an extra step. Depth counters
 * and "has this rule run already" bookkeeping both work and both are subtle;
 * the honest version is a straight line:
 *
 *   **while a rule is running, no new rules are queued.**
 *
 * Chaining is therefore not supported, deliberately. It reads as a limitation
 * until you have watched a shop send four thousand webhooks about one order.
 * A rule that has to do two things does them as two actions, in order, which is
 * what the actions list is for.
 *
 * A singleton rather than a static: a static would outlive the request under a
 * persistent worker, and a guard stuck "on" would silently stop every rule in
 * the shop.
 */
class RuleGuard
{
    protected bool $running = false;

    public function running(): bool
    {
        return $this->running;
    }

    /** @template T @param  callable(): T  $work @return T */
    public function while(callable $work): mixed
    {
        $was = $this->running;
        $this->running = true;

        try {
            return $work();
        } finally {
            $this->running = $was;
        }
    }
}

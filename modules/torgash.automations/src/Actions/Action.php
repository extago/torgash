<?php

namespace Torgash\Automations\Actions;

use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Models\RuleAction;

/**
 * What a rule can do once its conditions hold.
 *
 * A module adds its own by answering `automations.actions` with
 * `['my_type' => MyAction::class]`; the engine handles the trigger, the delay,
 * the conditions, the retry and the log, and the action only has to do the one
 * thing it is for. That split is the whole reason a vendor-specific integration
 * stays thin here instead of being a module of its own.
 *
 * `$subject` is whatever the rule is about — an order, an enquiry (see
 * Subjects\Subject). An action that only makes sense for one of them says so in
 * `subjects()`; one that only prints the context (a webhook, a chat message)
 * answers every subject with an empty list.
 */
interface Action
{
    /** English source label for the action picker. */
    public static function label(): string;

    /**
     * The subject keys this action can act on; [] means any.
     *
     * @return list<string>
     */
    public static function subjects(): array;

    /**
     * Do it. Return what the run log should say.
     *
     * `retry => true` beside a failed status says the receiver did not TAKE
     * the action — the host was not reached, the connection dropped, it
     * answered "try later" (429) or an error of its own (5xx) — and RunRule
     * runs this one action again after the shop's interval, as many times
     * as the shop allows. Never for a refusal (4xx, a bad token, a missing
     * field): that one is final until somebody fixes it.
     *
     * @param  array<string, mixed>  $context  the rendered variables
     * @return array{status: string, summary: string, detail: array<string, mixed>, retry?: bool}
     */
    public function run(RuleAction $action, Model $subject, array $context, string $idempotencyKey): array;
}

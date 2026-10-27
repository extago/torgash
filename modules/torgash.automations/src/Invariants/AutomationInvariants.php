<?php

namespace Torgash\Automations\Invariants;

use Illuminate\Support\Facades\DB;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Triggers;

/**
 * What must be true about the rules, said by the module that runs them.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the guard
 * being installed.
 *
 * A rule is the one thing in a shop that is SUPPOSED to be silent. Nobody
 * watches it work — that is why it was written — so a rule that has stopped
 * working looks exactly like a rule that is working and simply has had nothing
 * to do. The shop finds out in a month, from a customer who was never written
 * to.
 *
 * Both statements are about the two ways a rule stops without breaking: the
 * trigger it waits for is no longer announced by anybody, or the action it
 * would take is no longer answered for. Both are registry KEYS stored in a
 * column, and a key outlives the module that answered for it — the same shape
 * as a payment method bound to a gateway that has been uninstalled.
 */
class AutomationInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'automations.rules_can_act' => [
                'label' => 'Every rule that is on has something it can actually do',
                'explain' => 'The rule fires and then does nothing: either it has no actions at all, or one of them is of a kind no module answers for any more — usually a module that has been disabled or uninstalled. Open the rule, and either give it an action, put the missing module back, or switch the rule off so the shop stops relying on it.',
                'check' => function (int $limit) {
                    $known = array_keys(RunRule::actions());

                    return DB::table('m_automations_rules as r')
                        ->where('r.enabled', true)
                        ->where(fn ($q) => $q
                            ->whereNotExists(fn ($sub) => $sub
                                ->from('m_automations_actions as a')
                                ->whereColumn('a.rule_id', 'r.id'))
                            ->orWhereExists(fn ($sub) => $sub
                                ->from('m_automations_actions as a')
                                ->whereColumn('a.rule_id', 'r.id')
                                ->when($known !== [], fn ($w) => $w->whereNotIn('a.type', $known))))
                        ->orderBy('r.id')
                        ->limit($limit)
                        ->get(['r.id', 'r.name'])
                        ->map(function ($row) use ($known) {
                            $types = DB::table('m_automations_actions')->where('rule_id', $row->id)->pluck('type');
                            $missing = $types->reject(fn ($type) => in_array($type, $known, true))->unique()->values();

                            return [
                                'id' => (string) $row->id,
                                'title' => $row->name ?: '#'.$row->id,
                                'detail' => $types->isEmpty()
                                    ? ['No actions at all.']
                                    : ['Nothing answers for the action ":types".', ['types' => $missing->implode(', ')]],
                                'url' => route('admin.automations.edit', $row->id),
                            ];
                        });
                },
            ],

            // A trigger is a HOOK NAME rather than an invented event, which is
            // what lets another module offer one — and what lets that module go
            // away again, leaving a rule waiting for a string nobody says.
            'automations.triggers_are_announced' => [
                'label' => 'Every rule that is on waits for something the shop still announces',
                'explain' => 'The rule listens for an event no module announces any more, so it never fires at all — which looks exactly like a rule that has had nothing to do. Put the module that offered that event back, point the rule at an event the shop does announce, or switch the rule off.',
                'check' => function (int $limit) {
                    $known = array_keys(Triggers::all());

                    return DB::table('m_automations_rules')
                        ->where('enabled', true)
                        ->when($known !== [], fn ($q) => $q->whereNotIn('event', $known))
                        ->orderBy('id')
                        ->limit($limit)
                        ->get(['id', 'name', 'event'])
                        ->map(fn ($row) => [
                            'id' => (string) $row->id,
                            'title' => $row->name ?: '#'.$row->id,
                            'detail' => ['Waits for ":event", which nothing announces.', ['event' => (string) $row->event]],
                            'url' => route('admin.automations.edit', $row->id),
                        ]);
                },
            ],
        ];
    }
}

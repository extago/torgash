<?php

namespace Torgash\Automations;

use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Subjects\Subjects;

/**
 * A rule, packed so somebody else can use it.
 *
 * The point of a template is that the hard part is shareable and the private
 * part is not. What takes an afternoon is the BODY — which fields the receiving
 * system wants, what it calls them, which of them are minor units — and that
 * travels. What must never travel is the credential.
 *
 * So the rule is blunt and needs no cleverness about "does this look like a
 * token": **an exported template carries no URL and no header value.** Both are
 * always shop-specific — the endpoint is your account's, the key is yours — and
 * the import form asks for them by name. A template is a shape, not a
 * subscription, and the first template somebody posts on a forum cannot take
 * their key with it.
 */
class RuleTemplate
{
    /** Bumped only if the shape stops being readable by an older importer. */
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public static function export(Rule $rule): array
    {
        $rule->loadMissing(['conditions', 'actions']);

        $needs = [];
        $actions = [];

        foreach ($rule->actions as $index => $action) {
            if ($action->type === 'telegram') {
                // The bot and the chat are this shop's own; the message is
                // what travels. Both are asked for by name on import.
                $needs[] = ['path' => "actions.{$index}.token", 'label' => __('Bot token'), 'secret' => true];
                $needs[] = ['path' => "actions.{$index}.chat_id", 'label' => __('Chat id'), 'secret' => false];
                $actions[] = ['type' => 'telegram', 'text' => (string) $action->config('text', '')];

                continue;
            }

            if ($action->type !== 'webhook') {
                // An internal action has nothing private in it. The status CODE
                // travels (it may or may not exist in the importing shop, and
                // the run log says so loudly if it does not); a manager id does
                // not — it names a person in somebody else's staff list, so the
                // slot arrives empty for the admin to fill in the editor.
                $config = $action->config ?? [];
                unset($config['manager_id']);

                $actions[] = ['type' => $action->type] + $config;

                continue;
            }

            $headers = [];

            foreach (self::headerNames($action->config('headers', '')) as $name) {
                $headers[] = $name.': ';
                $needs[] = [
                    'path' => "actions.{$index}.headers.{$name}",
                    'label' => $name,
                    'secret' => true,
                ];
            }

            $needs[] = [
                'path' => "actions.{$index}.url",
                'label' => __('Where to send it'),
                'secret' => false,
            ];

            $actions[] = [
                'type' => 'webhook',
                'method' => $action->config('method', 'POST'),
                'url' => '',
                'headers' => implode("\n", $headers),
                'body' => (string) $action->config('body', ''),
            ];
        }

        return [
            'torgash_automation' => self::VERSION,
            'name' => $rule->name,
            'event' => $rule->event,
            'fields' => $rule->fields,
            'delay_minutes' => $rule->delay_minutes,
            'conditions' => $rule->conditions->map(fn ($condition) => [
                'kind' => $condition->kind,
                'operator' => $condition->operator,
                // Category ids name rows in THIS shop's catalogue and mean
                // nothing in another; the kind and operator travel, the
                // selection is re-made in the editor — same reason a manager id
                // does not travel.
                'value' => $condition->kind === 'product_category'
                    ? ['ids' => []]
                    : $condition->value,
            ])->all(),
            'actions' => $actions,
            'needs' => $needs,
        ];
    }

    /**
     * What the importer has to be asked before this template can run.
     *
     * Read from the payload rather than trusted from its own `needs` key: a
     * template edited by hand (or written by somebody else's tooling) still has
     * to produce a form that matches what is actually in it.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{path: string, label: string, secret: bool}>
     */
    public static function needs(array $payload): array
    {
        $needs = [];

        foreach ((array) ($payload['actions'] ?? []) as $index => $action) {
            if (($action['type'] ?? '') === 'telegram') {
                $needs[] = ['path' => "actions.{$index}.token", 'label' => __('Bot token'), 'secret' => true];
                $needs[] = ['path' => "actions.{$index}.chat_id", 'label' => __('Chat id'), 'secret' => false];

                continue;
            }

            if (($action['type'] ?? '') !== 'webhook') {
                continue;
            }

            $needs[] = [
                'path' => "actions.{$index}.url",
                'label' => __('Where to send it').(count((array) $payload['actions']) > 1 ? ' #'.($index + 1) : ''),
                'secret' => false,
            ];

            foreach (self::headerNames((string) ($action['headers'] ?? '')) as $name) {
                $needs[] = ['path' => "actions.{$index}.headers.{$name}", 'label' => $name, 'secret' => true];
            }
        }

        return $needs;
    }

    /**
     * Build the rule. Answers are keyed by the paths `needs()` handed out.
     *
     * Everything is filtered against what this shop can actually run — unknown
     * events, condition kinds, operators and action types are dropped, exactly
     * as they are when the form saves. A template from a shop with a module we
     * do not have must import as a rule missing that piece, not as a rule that
     * fails at three in the morning.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $answers
     */
    public static function import(array $payload, array $answers): Rule
    {
        $event = (string) ($payload['event'] ?? '');
        $event = isset(Triggers::all()[$event]) ? $event : 'checkout.order.changed';
        $subjectKey = Subjects::keyFor($event);
        $subject = Subjects::get($subjectKey);
        // Only what can act on THIS rule's subject: an order action arriving
        // on an enquiry rule would be refused at every run.
        $known = RunRule::actionsFor($subjectKey);
        $schema = $subject?->conditions() ?? [];

        $rule = Rule::create([
            'name' => trim((string) ($payload['name'] ?? '')) ?: __('Imported rule'),
            'event' => $event,
            'fields' => $subject?->usesFields($event)
                ? array_values(array_intersect((array) ($payload['fields'] ?? []), array_keys($subject->fields()))) ?: null
                : null,
            'delay_minutes' => max(0, (int) ($payload['delay_minutes'] ?? 0)),
            // Off on arrival, always. An imported rule points at an endpoint
            // nobody has tested from this shop yet, and the "Test" button is one
            // click away — enabling it silently is how a template's first act is
            // a wrong POST about a real order.
            'enabled' => false,
        ]);

        $position = 0;
        foreach ((array) ($payload['conditions'] ?? []) as $row) {
            $kind = (string) ($row['kind'] ?? '');
            $operator = (string) ($row['operator'] ?? '');

            if (! isset($schema[$kind]['operators'][$operator])) {
                continue;
            }

            $rule->conditions()->create([
                'kind' => $kind,
                'operator' => $operator,
                'value' => (array) ($row['value'] ?? []),
                'position' => $position++,
            ]);
        }

        $position = 0;
        foreach ((array) ($payload['actions'] ?? []) as $index => $row) {
            $type = (string) ($row['type'] ?? '');

            if (! isset($known[$type])) {
                continue;
            }

            $rule->actions()->create([
                'type' => $type,
                'config' => $type === 'telegram'
                    ? [
                        'token' => trim((string) ($answers["actions.{$index}.token"] ?? '')),
                        'chat_id' => trim((string) ($answers["actions.{$index}.chat_id"] ?? '')),
                        'text' => (string) ($row['text'] ?? ''),
                    ]
                    : ($type === 'webhook'
                    ? [
                        'method' => in_array($row['method'] ?? '', ['POST', 'PUT', 'PATCH', 'GET'], true) ? $row['method'] : 'POST',
                        'url' => trim((string) ($answers["actions.{$index}.url"] ?? '')),
                        'headers' => self::fillHeaders((string) ($row['headers'] ?? ''), $index, $answers),
                        'body' => (string) ($row['body'] ?? ''),
                    ]
                    : array_diff_key((array) $row, array_flip(['type']))),
                'position' => $position++,
            ]);
        }

        return $rule->fresh(['conditions', 'actions']);
    }

    /**
     * The header names a block declares, in order and without their values.
     *
     * @return list<string>
     */
    protected static function headerNames(string $headers): array
    {
        $names = [];

        foreach (preg_split('/\R/', $headers) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            $name = trim(explode(':', $line, 2)[0]);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * A header the importer left blank is dropped, not sent empty: blank means
     * "I do not have this one", and an empty `Authorization:` is a request that
     * fails in a way nobody recognises. The editor still shows the block, so
     * adding it later is a line of typing.
     *
     * @param  array<string, string>  $answers
     */
    protected static function fillHeaders(string $headers, int $index, array $answers): string
    {
        $filled = [];

        foreach (self::headerNames($headers) as $name) {
            $value = trim((string) ($answers["actions.{$index}.headers.{$name}"] ?? ''));

            if ($value !== '') {
                $filled[] = $name.': '.$value;
            }
        }

        return implode("\n", $filled);
    }
}

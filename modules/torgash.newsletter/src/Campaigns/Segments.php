<?php

namespace Torgash\Newsletter\Campaigns;

use Illuminate\Database\Eloquent\Builder;
use Torgash\Newsletter\Models\Subscriber;

/**
 * Who a campaign goes to (PLAN §57.4): the SUBSCRIBED addresses, narrowed by
 * conditions that must ALL hold. A condition is a kind of the registry
 * `newsletter.segments`, answered by whoever knows the answer — this module
 * the language, the orders module "bought", the points module "has points" —
 * so the newsletter names no other module's tables:
 *
 *     hook_listen('newsletter.segments', fn (array $kinds) => $kinds + [
 *         'bought' => [
 *             'label' => 'Bought',                                  // English, the owner's lang file
 *             'fields' => [['key' => 'days', 'type' => 'number', 'label' => 'In the last … days', 'help' => '…']],
 *             'apply' => fn (Builder $subscribers, array $params) => $subscribers->whereExists(…),
 *         ],
 *     ]);
 *
 * A field is `number`, `text` or `select` (with `options`, a map or a
 * closure). `apply` narrows a query of `m_newsletter_subscribers`; a kind
 * nobody answers any more narrows to NOBODY — a campaign meant for "bought"
 * must not go to everybody because the orders module was switched off.
 */
class Segments
{
    /** @return array<string, array{label: string, fields: list<array<string, mixed>>, apply: callable}> */
    public function kinds(): array
    {
        $kinds = (array) hook_filter('newsletter.segments', [
            'language' => [
                'label' => 'Language of the letters',
                'fields' => [[
                    'key' => 'locale', 'type' => 'select', 'label' => 'Language',
                    'options' => fn () => \App\Models\Language::where('enabled', true)->pluck('code')->mapWithKeys(fn ($l) => [$l => strtoupper($l)])->all(),
                ]],
                'apply' => fn (Builder $q, array $p) => ($p['locale'] ?? '') === ''
                    ? $q
                    // The language the letter will be written in — the account's first (SendCampaigns::LOCALE).
                    : $q->where(fn ($w) => $w->whereRaw(SendCampaigns::LOCALE.' = ?', [$p['locale']])
                        // An address without a language reads the shop's base one.
                        ->when($p['locale'] === content_base_locale(), fn ($w) => $w->orWhereRaw(SendCampaigns::LOCALE.' is null'))),
            ],
            'source' => [
                'label' => 'Where they subscribed',
                'fields' => [[
                    'key' => 'source', 'type' => 'select', 'label' => 'Where from',
                    'options' => fn () => self::sourceLabels(),
                ]],
                'apply' => fn (Builder $q, array $p) => in_array($p['source'] ?? '', Subscriber::SOURCES, true) ? $q->where('source', $p['source']) : $q,
            ],
        ]);

        return array_filter($kinds, fn ($kind) => is_array($kind) && is_callable($kind['apply'] ?? null));
    }

    /** @return array<string, string> */
    public static function sourceLabels(): array
    {
        return [
            'block' => 'Subscription block', 'cart' => 'Basket page', 'checkout' => 'Checkout',
            'account' => 'Account', 'import' => 'Import', 'admin' => 'Admin',
        ];
    }

    /**
     * The subscribed addresses a segment means.
     *
     * @param  array{rules?: list<array<string, mixed>>}|null  $segment
     */
    public function query(?array $segment): Builder
    {
        $kinds = $this->kinds();
        $query = Subscriber::query()->where('status', Subscriber::SUBSCRIBED);

        foreach ((array) ($segment['rules'] ?? []) as $rule) {
            $kind = $kinds[(string) ($rule['kind'] ?? '')] ?? null;

            if ($kind === null) {
                return $query->whereRaw('1 = 0');
            }

            $query = ($kind['apply'])($query, (array) $rule) ?? $query;
        }

        return $query;
    }

    /**
     * Rules as typed on the form: known kinds only, each with only its own
     * fields — what is stored on the campaign.
     *
     * @return array{rules: list<array<string, mixed>>}
     */
    public function clean(array $rules): array
    {
        $kinds = $this->kinds();
        $out = [];

        foreach ($rules as $rule) {
            $key = (string) ($rule['kind'] ?? '');

            if (! is_array($rule) || ! isset($kinds[$key])) {
                continue;
            }

            $clean = ['kind' => $key];
            foreach ((array) ($kinds[$key]['fields'] ?? []) as $field) {
                $value = trim((string) ($rule[$field['key']] ?? ''));
                $clean[$field['key']] = ($field['type'] ?? 'text') === 'number' ? ($value === '' ? '' : (string) max(0, (int) $value)) : mb_substr($value, 0, 190);
            }
            $out[] = $clean;
        }

        return ['rules' => $out];
    }

    /** The registry as the form's repeater reads it: words in the admin's language, options resolved. */
    public function forForm(): array
    {
        return collect($this->kinds())->map(fn (array $kind) => [
            'label' => label_text($kind['label']),
            'fields' => collect((array) ($kind['fields'] ?? []))->map(fn (array $field) => [
                'key' => $field['key'],
                'type' => $field['type'] ?? 'text',
                'label' => label_text($field['label'] ?? $field['key']),
                'help' => isset($field['help']) ? label_text($field['help']) : null,
                'options' => is_callable($field['options'] ?? null)
                    ? collect(($field['options'])())->map(fn ($l) => label_text($l))->all()
                    : collect((array) ($field['options'] ?? []))->map(fn ($l) => label_text($l))->all(),
            ])->values()->all(),
        ])->all();
    }
}

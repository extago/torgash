<?php

namespace App\Core\Seo;

/**
 * `/llms.txt` — the shop, in one page, for a reader that is a program.
 *
 * There is no standard, only a convention (a Markdown page: an H1 with the
 * name, a blockquote saying what this is, then H2 sections of annotated
 * links). The convention is the whole value: an agent that finds this file
 * needs no guesswork about where the catalogue is, that the API describes
 * itself, or that a page can be had as text.
 *
 * It costs one template because everything in it already exists — the map is
 * assembled from what modules already publish, and each of them ANSWERS for
 * its own half through `core.llms.sections`. Core writes the heading and the
 * house rules and knows the name of no module, exactly as it does for the
 * account menu and the robots file.
 *
 * Served only when the shop actually invites machine readers (`Robots::
 * invitesAgents()`): a map handed to an audience the robots file just turned
 * away is the shop contradicting itself in two files at the same address.
 */
class LlmsMap
{
    public static function render(): string
    {
        $storefront = current_storefront();
        $name = $storefront?->name ?: config('app.name');

        $out = ['# '.$name, ''];

        if ($summary = trim((string) setting('core', 'llms_summary', ''))) {
            $out[] = '> '.str_replace("\n", ' ', $summary);
            $out[] = '';
        }

        foreach (self::sections() as $section) {
            $out[] = '## '.$section['title'];
            $out[] = '';

            if ($text = trim((string) ($section['text'] ?? ''))) {
                $out[] = $text;
                $out[] = '';
            }

            foreach ($section['items'] ?? [] as $item) {
                $note = trim((string) ($item['note'] ?? ''));
                $out[] = '- ['.$item['label'].']('.$item['url'].')'.($note === '' ? '' : ': '.$note);
            }

            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * @return list<array{title: string, position: int, text?: string, items?: list<array{label: string, url: string, note?: string}>}>
     */
    public static function sections(): array
    {
        // Answered lazily, at request time, for the reason api.resources is:
        // provider boot order is not ours to depend on.
        $sections = hook_filter('core.llms.sections', [], []);

        $sections[] = self::houseRules();

        usort($sections, fn ($a, $b) => ($a['position'] ?? 50) <=> ($b['position'] ?? 50));

        return array_values($sections);
    }

    /**
     * What the shop permits, said in words rather than left to be inferred
     * from robots.txt — an agent acting for a person reads prose better than
     * it reads a rules file, and the two must not be able to disagree, so this
     * is derived from the same settings rather than typed a second time.
     */
    protected static function houseRules(): array
    {
        $storefront = current_storefront();
        $lines = [];

        if ($storefront) {
            $lines[] = '- '.__('Languages: :locales. The same page in another language is its address under that language prefix, for example /ru/.', [
                'locales' => implode(', ', $storefront->locales ?: [$storefront->default_locale]),
            ]);
            $lines[] = '- '.__('Prices are quoted in :currency.', ['currency' => $storefront->default_currency]);
        }

        foreach (Robots::groups() as $group) {
            if ($group['agents'] === []) {
                continue;
            }

            $lines[] = '- '.($group['allowed']
                ? __('Allowed: :audience. robots.txt names them one by one.', ['audience' => __($group['comment'])])
                : __('Not allowed: :audience. robots.txt names them one by one.', ['audience' => __($group['comment'])]));
        }

        $lines[] = '- '.__('Please stay under :limit requests per minute for the text pages; beyond that the shop answers 429.', [
            'limit' => (int) setting('core', 'agent_rate_limit', 60),
        ]);

        return [
            'title' => __('House rules'),
            'position' => 90,
            'text' => implode("\n", $lines),
        ];
    }
}

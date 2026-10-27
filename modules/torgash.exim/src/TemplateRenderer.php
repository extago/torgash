<?php

namespace Torgash\Exim;

use App\Core\Content\SafeTemplate;
use Torgash\Exim\Models\EximProfile;
use Torgash\Exim\Template\FeedTemplate;

/**
 * Full-custom output shape — a marketplace feed, one CRM's oddball XML — the
 * admin writes the file around the rows:
 *
 *   <offers>
 *   {{#rows}}  <offer id="{{ item.id }}">{{ item.name.ru }}</offer>
 *   {{/rows}}</offers>
 *
 * The language is `Template\FeedTemplate` (Mustache's sections, filters);
 * it used to be Blade, which compiles to PHP — anybody allowed to edit a
 * profile could run code on the server. A column in several languages is
 * `item.name.ru`; a price type's own price `item.price_type.WHOLESALE`; a
 * characteristic `item.feature.<code>`. Around the rows, `profile.*` and
 * whatever the entity's module offers (`exim.template.context` — the shop,
 * its currencies and categories for a product feed).
 *
 * Streamed: what stands before `{{#rows}}` once, the block per row as the
 * query yields it, the rest once — a feed of fifty thousand products never
 * becomes one array. Values are escaped for the profile's format (XML/JSON;
 * CSV is written as typed).
 */
class TemplateRenderer
{
    /**
     * @param  iterable<array<string, mixed>>  $rows  column key => value
     * @param  list<array>  $columns
     * @return \Generator<string>
     */
    public function render(EximProfile $profile, iterable $rows, array $columns): \Generator
    {
        $escape = match ($profile->format) {
            'xml' => SafeTemplate::XML,
            'json' => SafeTemplate::JSON,
            default => SafeTemplate::RAW,
        };
        $context = self::context($profile);
        $nodes = (new FeedTemplate((string) $profile->template_body, strict: false))->nodes();

        $at = null;
        foreach ($nodes as $i => $node) {
            if ($node[0] === 'section' && $node[1] === 'rows' && ! $node[2]) {
                $at = $i;
                break;
            }
        }

        if ($at === null) {
            yield FeedTemplate::renderNodes($nodes, [$context], $escape);

            return;
        }

        yield FeedTemplate::renderNodes(array_slice($nodes, 0, $at), [$context], $escape);

        foreach ($rows as $row) {
            yield FeedTemplate::renderNodes($nodes[$at][3], [$context, ['item' => self::nest($row, $columns)]], $escape);
        }

        yield FeedTemplate::renderNodes(array_slice($nodes, $at + 1), [$context], $escape);
    }

    /** What the template has around the rows. */
    public static function context(EximProfile $profile): array
    {
        return hook_filter('exim.template.context', [
            'profile' => ['name' => $profile->t('name'), 'generated_at' => now()->toIso8601String()],
        ], ['entity' => $profile->entity, 'profile' => $profile]);
    }

    /**
     * The column keys a template uses (`item.sku` → `sku`, `item.name.ru` →
     * `name`, `item.feature.memory` → `feature:memory`) — all an export has to
     * read for it.
     *
     * @param  array<string, array>  $declared  by key
     * @param  array<string, array>  $columns  every column, by key
     * @return list<string>
     */
    public static function usedKeys(string $template, array $declared, array $columns): array
    {
        $keys = [];

        foreach ((new FeedTemplate($template, strict: false))->paths() as $path) {
            $segments = explode('.', $path);
            if ($segments[0] !== 'item' || ! isset($segments[1])) {
                continue;
            }
            if (isset($declared[$segments[1]])) {
                $keys[] = $segments[1];
            } elseif (isset($segments[2]) && isset($columns[$segments[1].':'.$segments[2]])) {
                $keys[] = $segments[1].':'.$segments[2];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * A flat row as a template addresses it: `name_ru` becomes `name.ru`,
     * `price_type:WHOLESALE` becomes `price_type.WHOLESALE`.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array>  $columns
     */
    public static function nest(array $row, array $columns): array
    {
        $item = [];

        foreach ($columns as $column) {
            $value = $row[$column['key']] ?? null;

            if (isset($column['locale'])) {
                $item[$column['base_key']][$column['locale']] = $value;
            } elseif (str_contains($column['key'], ':')) {
                [$head, $tail] = explode(':', $column['key'], 2);
                $item[$head][$tail] = $value;
            } else {
                $item[$column['key']] = $value;
            }
        }

        return $item;
    }
}

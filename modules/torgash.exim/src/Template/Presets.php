<?php

namespace Torgash\Exim\Template;

/**
 * Ready templates a profile can start from — a marketplace feed somebody
 * already wrote (Yandex Market's YML). Files in `templates/` of the module
 * that ships them, listed through `exim.template_presets`:
 *
 *   ['key' => 'yandex-market', 'title' => 'Yandex Market (YML)', 'entity' => 'product',
 *    'format' => 'xml', 'file' => __DIR__.'/../templates/yandex-market.xml']
 *
 * `@lang@` in a file is the shop's content language, so `item.name.@lang@`
 * names the text the shop actually has.
 */
class Presets
{
    /** @return list<array{key: string, title: string, entity: string, format: string, body: string}> */
    public static function all(): array
    {
        $presets = hook_filter('exim.template_presets', [[
            'key' => 'yandex-market',
            'title' => 'Yandex Market (YML)',
            'entity' => 'product',
            'format' => 'xml',
            'file' => dirname(__DIR__, 2).'/templates/yandex-market.xml',
        ]], []);

        return array_values(array_filter(array_map(fn (array $preset) => is_file($preset['file'] ?? '') ? [
            'key' => (string) $preset['key'],
            'title' => (string) $preset['title'],
            'entity' => (string) ($preset['entity'] ?? 'product'),
            'format' => (string) ($preset['format'] ?? 'xml'),
            'body' => str_replace('@lang@', content_base_locale(), (string) file_get_contents($preset['file'])),
        ] : null, $presets)));
    }
}

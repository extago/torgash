<?php

namespace Torgash\Automations;

/**
 * Ready-made rules (`presets/*.json`): "payment received", "large order",
 * "one-click orders to a manager"… — templates in RuleTemplate's own shape
 * whose words are maps by language. Picked one goes through the same import
 * as a pasted template: it asks for the bot and the chat by name and arrives
 * switched off.
 *
 * The rule's name and message become the shop's own text, so they are taken
 * in the shop's content language (`content_base_locale()`), English when the
 * preset does not speak it.
 */
class RulePresets
{
    /** @return array<string, array{name: string, about: string, event: string}> slug => what the list shows */
    public static function all(): array
    {
        $presets = [];

        foreach (glob(self::path().'/*.json') ?: [] as $file) {
            $slug = basename($file, '.json');
            $payload = self::payload($slug);

            if ($payload !== null) {
                $presets[$slug] = [
                    'name' => (string) $payload['name'],
                    'about' => (string) ($payload['about'] ?? ''),
                    'event' => (string) $payload['event'],
                ];
            }
        }

        return $presets;
    }

    /**
     * One preset as an import payload, its words in one language.
     *
     * @return array<string, mixed>|null
     */
    public static function payload(string $slug): ?array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $slug) || ! is_file($file = self::path().'/'.$slug.'.json')) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        if (! is_array($payload) || ! isset($payload['torgash_automation'], $payload['event'])) {
            return null;
        }

        $payload['name'] = self::words($payload['name'] ?? '');
        $payload['about'] = self::words($payload['about'] ?? '');

        foreach ((array) ($payload['actions'] ?? []) as $index => $action) {
            foreach (['text', 'body'] as $key) {
                if (isset($action[$key])) {
                    $payload['actions'][$index][$key] = self::words($action[$key]);
                }
            }
        }

        return $payload;
    }

    /** A map by language, said in the shop's; a plain string as it is. */
    protected static function words(mixed $value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return (string) ($value[content_base_locale()] ?? $value['en'] ?? reset($value) ?: '');
    }

    protected static function path(): string
    {
        return dirname(__DIR__).'/presets';
    }
}

<?php

namespace App\Core\Support;

/**
 * How a phone field on the storefront helps the hand that types it (core
 * settings `phone_mask` / `phone_mask_custom`, overridable per storefront
 * from its own form). Only TYPING: whether the number is real is still
 * `Phones`' answer on the server, by its country's rules — a mask that
 * fits is not a number that exists.
 *
 * A pattern is literal characters and `_` for a digit: `+380 (__)
 * ___-__-__`. Not `9`, the old jQuery habit: a country code is digits too
 * (+998), and a placeholder that is also a digit cannot spell it. `trunk` is
 * the national prefix a person types out of habit and the pattern already
 * stands for (Ukraine's 0, Russia's 8), dropped when typed first.
 * `international` has no pattern: a plus and up to fifteen digits, grouped
 * as typed (assets/js/phone-mask.js).
 */
final class PhoneMask
{
    public const PRESETS = [
        'ua' => ['pattern' => '+380 (__) ___-__-__', 'trunk' => '0'],
        'ru' => ['pattern' => '+7 (___) ___-__-__', 'trunk' => '8'],
        'international' => ['pattern' => '', 'trunk' => ''],
    ];

    /** The choices, as the settings screen and the storefront form offer them. */
    public const OPTIONS = [
        'none' => 'No mask',
        'ua' => 'Ukraine: +380 (__) ___-__-__',
        'ru' => 'Russia, Kazakhstan: +7 (___) ___-__-__',
        'international' => 'International: + and the digits',
        'custom' => 'A pattern of my own',
    ];

    /**
     * The mask for a storefront (its own choice, else the shop's), or null
     * for none.
     *
     * @return array{mode: string, pattern: string, trunk: string}|null
     */
    public static function for(?int $storefrontId): ?array
    {
        $mode = (string) setting('core', 'phone_mask', 'none', $storefrontId);

        if ($mode === 'custom') {
            $pattern = self::cleanPattern((string) setting('core', 'phone_mask_custom', '', $storefrontId));

            return $pattern === null ? null : ['mode' => 'custom', 'pattern' => $pattern, 'trunk' => ''];
        }

        return isset(self::PRESETS[$mode]) ? ['mode' => $mode] + self::PRESETS[$mode] : null;
    }

    /** A typed pattern, or null when it cannot be one: no `_`, or characters a phone does not have. */
    public static function cleanPattern(string $pattern): ?string
    {
        $pattern = trim($pattern);

        return $pattern !== '' && str_contains($pattern, '_') && preg_match('/^[0-9_+() .\-]+$/', $pattern) && substr_count($pattern, '_') <= 15
            ? $pattern
            : null;
    }
}

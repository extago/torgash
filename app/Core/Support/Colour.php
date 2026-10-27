<?php

namespace App\Core\Support;

/**
 * Colour arithmetic for the theme's colours (PLAN §52): WCAG contrast, the
 * text that reads on a colour, and a dark-palette twin worked out from a
 * light one.
 *
 * The twin is made in OKLCH, not by mixing with black: lightness there is
 * what the eye calls lightness, so "a text colour for a dark page" keeps its
 * hue and only rises until it reads, and a bar's colour sinks without going
 * grey. Never applied to a colour the shop TYPED for the dark palette — the
 * shop's choice prints exactly as chosen (the lesson of 2026-10-16); this
 * only answers "automatic".
 *
 * The same formulas run in the configurator's script, so the swatch shown
 * for "automatic" is the colour the page gets.
 */
final class Colour
{
    public const LIGHT_INK = '#ffffff';

    public const DARK_INK = '#18181b';

    /** What WCAG AA asks of body text. */
    public const AA = 4.5;

    public static function valid(mixed $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1;
    }

    /** @return array{0: float, 1: float, 2: float} sRGB channels 0..1 */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    public static function hex(float $r, float $g, float $b): string
    {
        $c = fn (float $v) => str_pad(dechex((int) round(max(0, min(1, $v)) * 255)), 2, '0', STR_PAD_LEFT);

        return '#'.$c($r).$c($g).$c($b);
    }

    /** WCAG relative luminance. */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(fn (float $v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** White or near-black, whichever reads better on $background. */
    public static function ink(string $background): string
    {
        return self::contrast($background, self::LIGHT_INK) >= self::contrast($background, self::DARK_INK)
            ? self::LIGHT_INK : self::DARK_INK;
    }

    /** @return array{0: float, 1: float, 2: float} L 0..1, C, H degrees */
    public static function oklch(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (float $v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, self::rgb($hex));

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        [$l, $m, $s] = [self::cbrt($l), self::cbrt($m), self::cbrt($s)];

        $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $A = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $B = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $h = rad2deg(atan2($B, $A));

        return [$L, sqrt($A * $A + $B * $B), $h < 0 ? $h + 360 : $h];
    }

    /** OKLCH back to hex, chroma lowered until the colour fits in sRGB. */
    public static function fromOklch(float $L, float $C, float $H): string
    {
        for ($i = 0; $i < 40; $i++) {
            $rgb = self::oklchToLinear($L, $C, $H);
            if (min($rgb) >= -0.0005 && max($rgb) <= 1.0005) {
                break;
            }
            $C *= 0.9;
        }

        $g = fn (float $v) => $v <= 0.0031308 ? 12.92 * $v : 1.055 * (max(0, $v) ** (1 / 2.4)) - 0.055;

        return self::hex($g($rgb[0]), $g($rgb[1]), $g($rgb[2]));
    }

    /**
     * The dark-palette twin of a light colour, by what the colour IS:
     *
     * - `text` (a price, a link on the page): rises until it reads on
     *   $darkBackground;
     * - `surface` (a bar, a panel): sinks to a deep shade of the same hue;
     * - anything else (a brand colour, a state): stays — a button keeps its
     *   colour on a dark page, which is how the theme ships.
     */
    public static function darkTwin(string $light, string $role, string $darkBackground = '#1c2230'): string
    {
        [$L, $C, $H] = self::oklch($light);

        return match ($role) {
            'text' => self::readableOn($light, $darkBackground),
            'surface' => self::fromOklch(min($L, 0.27), $C * 0.55, $H),
            default => strtolower($light),
        };
    }

    /**
     * The nearest shade of $colour that reads on $background (AA), moving
     * lightness only — away from the background. What "pick a readable
     * shade" does in the configurator, on request and never by itself.
     */
    public static function readableOn(string $colour, string $background, float $ratio = self::AA): string
    {
        if (self::contrast($colour, $background) >= $ratio) {
            return strtolower($colour);
        }

        [$L, $C, $H] = self::oklch($colour);
        $up = self::luminance($background) < 0.18;

        for ($i = 0; $i < 100; $i++) {
            $L = max(0, min(1, $L + ($up ? 0.01 : -0.01)));
            $candidate = self::fromOklch($L, $C, $H);
            if (self::contrast($candidate, $background) >= $ratio || $L <= 0 || $L >= 1) {
                return $candidate;
            }
        }

        return $up ? self::LIGHT_INK : self::DARK_INK;
    }

    /** @return array{0: float, 1: float, 2: float} linear sRGB */
    private static function oklchToLinear(float $L, float $C, float $H): array
    {
        $A = $C * cos(deg2rad($H));
        $B = $C * sin(deg2rad($H));

        $l = ($L + 0.3963377774 * $A + 0.2158037573 * $B) ** 3;
        $m = ($L - 0.1055613458 * $A - 0.0638541728 * $B) ** 3;
        $s = ($L - 0.0894841775 * $A - 1.2914855480 * $B) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private static function cbrt(float $v): float
    {
        return $v < 0 ? -((-$v) ** (1 / 3)) : $v ** (1 / 3);
    }
}

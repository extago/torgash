<?php

namespace Torgash\Media;

use Spatie\Image\Enums\Fit;

/**
 * Typed reader over this module's manifest settings. Everything that touches
 * an image goes through here so the admin's numbers are the only source of
 * truth for limits, thumbnail sizes and formats.
 */
class ImageSettings
{
    public const MODULE = 'torgash.media';

    public static function uploadMaxBytes(): ?int
    {
        $mb = (float) self::get('upload_max_mb', 10);

        return $mb > 0 ? (int) round($mb * 1024 * 1024) : null;
    }

    /** Longest side the stored original may have, or null when unrestricted. */
    public static function maxDimension(): ?int
    {
        if (self::originalUntouched()) {
            return null;
        }

        $px = (int) self::get('max_dimension_px', 2400);

        return $px > 0 ? $px : null;
    }

    public static function originalUntouched(): bool
    {
        return (bool) self::get('original_untouched', false);
    }

    /**
     * Whether an image dropped into a TEXT is re-encoded as WebP on the way in.
     *
     * Off by default, and that is a judgement rather than caution: a thumbnail
     * is generated in four sizes and served a million times, so WebP wins
     * outright there — but a content image is stored once and served as it is,
     * and for a screenshot full of text or a PNG with transparency the WebP
     * re-encode is sometimes visibly worse than the original. Which of those a
     * shop mostly uploads is not something the engine can know, so it is a
     * checkbox.
     */
    public static function contentWebp(): bool
    {
        return (bool) self::get('content_webp', false);
    }

    public static function originalQuality(): int
    {
        return self::clampQuality(self::get('original_quality', 88), 88);
    }

    /**
     * Parsed "120x120, 400x400" into [['name' => 'thumb-120x120', 'w' => 120, 'h' => 120], ...].
     * A bare number ("400") means a square box.
     */
    public static function thumbSizes(): array
    {
        $sizes = [];

        foreach (preg_split('/[,;\n]+/', (string) self::get('thumb_sizes', '')) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '' || ! preg_match('/^(\d+)\s*(?:[x×*]\s*(\d+))?$/iu', $chunk, $m)) {
                continue;
            }

            $w = (int) $m[1];
            $h = (int) ($m[2] ?? $m[1]);

            if ($w < 1 || $h < 1) {
                continue;
            }

            $sizes["{$w}x{$h}"] = ['name' => "thumb-{$w}x{$h}", 'w' => $w, 'h' => $h];
        }

        return array_values($sizes);
    }

    /**
     * How a thumbnail meets its box.
     *
     * Fit::Crop is spatie's name for what everyone else calls "cover": scale to
     * cover the box, then cut the overflow. Fit::Fill is "pad": scale to fit,
     * then extend the canvas to the exact box with a background colour.
     */
    public static function thumbFit(): Fit
    {
        return match ((string) self::get('thumb_fit', 'contain')) {
            'cover' => Fit::Crop,
            'pad' => Fit::Fill,
            default => Fit::Contain,
        };
    }

    /** Only meaningful for the Pad mode; always a valid hex so the driver never chokes. */
    public static function thumbPadColor(): string
    {
        $color = trim((string) self::get('thumb_pad_color', '#ffffff'));

        return preg_match('/^#?[0-9a-f]{6}$/i', $color) ? (str_starts_with($color, '#') ? $color : '#'.$color) : '#ffffff';
    }

    public static function trimBorders(): bool
    {
        return (bool) self::get('trim_borders', false);
    }

    /** 0–50 %, as a fraction of the 0–255 channel range. */
    public static function trimTolerance(): float
    {
        $percent = (float) self::get('trim_tolerance', 6);

        return min(50.0, max(0.0, $percent)) / 100;
    }

    public static function thumbWebp(): bool
    {
        return (bool) self::get('thumb_webp', true);
    }

    public static function webpQuality(): int
    {
        return self::clampQuality(self::get('webp_quality', 82), 82);
    }

    public static function thumbQuality(): int
    {
        return self::clampQuality(self::get('thumb_quality', 85), 85);
    }

    public static function allowRemote(): bool
    {
        return (bool) self::get('allow_remote', true);
    }

    public static function remoteTimeout(): int
    {
        return max(1, (int) self::get('remote_timeout', 15));
    }

    protected static function get(string $key, mixed $default): mixed
    {
        $value = setting(self::MODULE, $key, $default);

        return $value === null || $value === '' ? $default : $value;
    }

    protected static function clampQuality(mixed $value, int $fallback): int
    {
        $q = (int) $value;

        return $q >= 1 && $q <= 100 ? $q : $fallback;
    }
}

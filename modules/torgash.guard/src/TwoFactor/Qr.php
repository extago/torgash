<?php

namespace Torgash\Guard\TwoFactor;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The picture, and the one dependency this feature takes:
 * `bacon/bacon-qr-code` (BSD-2, so it passes the project's licence rule).
 *
 * The opposite call from `Totp`, and the reason is the same in both directions.
 * TOTP is sixty lines with published test vectors, so a library there would be
 * trust bought for nothing. A QR code is Reed-Solomon error correction, four
 * encoding modes, eight mask patterns and a version table — a fortnight's work
 * with a subtle bug in it that shows up as "some phones cannot scan this".
 *
 * SVG, not PNG: it needs no GD (the arithmetic CAPTCHA in this same module has
 * to warn a shop whose PHP lacks it), it is a few kilobytes, it stays sharp on
 * a phone held up to a monitor, and it goes into the page as markup rather than
 * as a file written somewhere or a second request to authorise.
 */
class Qr
{
    /**
     * The `otpauth://` address as an inline `<svg>`, or '' if the library is
     * not installed — in which case the page still shows the secret as text and
     * the person types it in. A missing picture must not be a missing feature.
     */
    public static function svg(string $uri, int $size = 190): string
    {
        if (! class_exists(Writer::class)) {
            return '';
        }

        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd));

        // The XML prolog is valid in an .svg file and invalid inline in HTML,
        // so it is dropped. Everything after it is one <svg> element.
        return preg_replace('/^<\?xml.*?\?>\s*/s', '', $writer->writeString($uri)) ?? '';
    }

    /** What the authenticator app will call this entry: the shop, then who. */
    public static function issuer(): string
    {
        return (string) (current_storefront()?->name ?: config('app.name', 'Torgash'));
    }
}

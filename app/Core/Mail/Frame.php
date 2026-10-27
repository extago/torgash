<?php

namespace App\Core\Mail;

use Illuminate\Support\Facades\Storage;

/**
 * What every letter looks like around its words (PLAN §41): the shop's
 * colour, its logo (or its name where there is none), and a footer line —
 * core settings, edited on Admin → Letters. The footer takes the same
 * {tokens} and placeholders as a letter, so "{shop_name} · {phone}" follows
 * the storefront's own contacts.
 */
class Frame
{
    public const DEFAULT_COLOR = '#0f172a';

    public const DEFAULT_FOOTER = '{shop_name} · {shop_url}';

    public const DEFAULT_BACKGROUND = '#f1f5f9';

    /** @return array{color: string, background: string, logo: ?string, topline: string, footer: string} */
    public static function settings(): array
    {
        $color = (string) setting('core', 'mail_color', self::DEFAULT_COLOR);
        $logo = (string) setting('core', 'mail_logo', '');
        $background = (string) setting('core', 'mail_background', self::DEFAULT_BACKGROUND);

        return [
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : self::DEFAULT_COLOR,
            'background' => preg_match('/^#[0-9a-f]{6}$/i', $background) ? $background : self::DEFAULT_BACKGROUND,
            // A short line beside the logo (a slogan, a phone): {tokens} as in the footer.
            'topline' => (string) setting('core', 'mail_topline', ''),
            // An address a mail client can fetch from outside: the public
            // disk's own URL, whatever that disk is (a folder or a bucket).
            'logo' => $logo !== '' && Storage::disk('public')->exists($logo) ? Storage::disk('public')->url($logo) : null,
            'footer' => (string) setting('core', 'mail_footer', self::DEFAULT_FOOTER) ?: self::DEFAULT_FOOTER,
        ];
    }
}

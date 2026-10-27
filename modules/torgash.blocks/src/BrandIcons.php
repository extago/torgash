<?php

namespace Torgash\Blocks;

/**
 * Brand marks for the footer's blocks — social networks and payment systems
 * (Simple Icons, CC0; see icons/brands/README.md). Inline SVG in
 * `currentColor`, so a mark takes the colour of the bar it stands on, the way
 * the Lucide icons do.
 *
 * Kept apart from core's Lucide set on purpose: those are pictograms anyone
 * may use for anything; these are other companies' trademarks, and only these
 * two blocks draw them.
 */
class BrandIcons
{
    /** slug => the name a person reads ({mark:slug} in HTML draws the mark) */
    public const SOCIAL = [
        'telegram' => 'Telegram',
        'vk' => 'VK',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'x' => 'X',
        'whatsapp' => 'WhatsApp',
        'viber' => 'Viber',
        'odnoklassniki' => 'Odnoklassniki',
        'pinterest' => 'Pinterest',
        'linkedin' => 'LinkedIn',
        'threads' => 'Threads',
    ];


    public const PAYMENT = [
        'visa' => 'Visa',
        'mastercard' => 'Mastercard',
        'applepay' => 'Apple Pay',
        'googlepay' => 'Google Pay',
        'samsungpay' => 'Samsung Pay',
        'paypal' => 'PayPal',
        'stripe' => 'Stripe',
        'klarna' => 'Klarna',
        'alipay' => 'Alipay',
        'americanexpress' => 'American Express',
    ];

    /** The mark as inline SVG with the given classes, or null for an unknown slug. */
    public static function svg(string $slug, string $class = 'h-5 w-5'): ?string
    {
        if (! preg_match('/^[a-z0-9]+$/', $slug)) {
            return null;
        }

        $path = dirname(__DIR__).'/icons/brands/'.$slug.'.svg';

        if (! is_file($path)) {
            return null;
        }

        $svg = (string) file_get_contents($path);
        // The <title> is the brand's name in English; the block writes its
        // own label beside the mark, so the title would only be read twice.
        $svg = preg_replace('#<title>.*?</title>#s', '', $svg);

        return str_replace('<svg ', '<svg aria-hidden="true" fill="currentColor" class="'.e($class).'" ', $svg);
    }
}

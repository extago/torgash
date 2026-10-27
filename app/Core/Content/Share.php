<?php

namespace App\Core\Content;

/**
 * "Share" (<x-share>): where a page can be sent, as each network's own share
 * ADDRESS — a plain link, so nothing is loaded from anybody until somebody
 * presses one. Which of them a shop offers, and in what order, is core's
 * setting `share_networks`: the button is drawn by the blog, the pages and
 * whatever comes next, and a shop should not tick the same list three times.
 *
 * "Copy the link" is not here: it is always offered, being the one that
 * works for every messenger nobody listed.
 */
class Share
{
    public const SETTING = 'share_networks';

    /** The networks offered when the shop has not chosen. */
    public const DEFAULTS = ['telegram', 'viber', 'whatsapp', 'facebook', 'x'];

    /**
     * slug => [the name a person reads, a Lucide icon for when no module has
     * the brand's mark, the share address with {url} and {text}].
     */
    public const NETWORKS = [
        'telegram' => ['Telegram', 'send', 'https://t.me/share/url?url={url}&text={text}'],
        'viber' => ['Viber', 'phone', 'viber://forward?text={text}%20{url}'],
        'whatsapp' => ['WhatsApp', 'message-circle', 'https://wa.me/?text={text}%20{url}'],
        'facebook' => ['Facebook', 'thumbs-up', 'https://www.facebook.com/sharer/sharer.php?u={url}'],
        'x' => ['X', 'at-sign', 'https://twitter.com/intent/tweet?url={url}&text={text}'],
        'vk' => ['VK', 'share-2', 'https://vk.com/share.php?url={url}&title={text}'],
        'odnoklassniki' => ['Odnoklassniki', 'share-2', 'https://connect.ok.ru/offer?url={url}&title={text}'],
        'linkedin' => ['LinkedIn', 'briefcase', 'https://www.linkedin.com/sharing/share-offsite/?url={url}'],
        'threads' => ['Threads', 'at-sign', 'https://www.threads.net/intent/post?text={text}%20{url}'],
        'reddit' => ['Reddit', 'message-square', 'https://www.reddit.com/submit?url={url}&title={text}'],
        'email' => ['Email', 'mail', 'mailto:?subject={text}&body={url}'],
    ];

    /** @return array<string, string> slug => name, for the setting's ticks */
    public static function options(): array
    {
        return array_map(fn (array $network) => $network[0], self::NETWORKS);
    }

    /**
     * The shop's networks for one page, in the order above.
     *
     * @return list<array{slug: string, name: string, href: string, mark: string}>
     */
    public function links(string $url, string $text): array
    {
        $chosen = (array) setting('core', self::SETTING, self::DEFAULTS);
        $links = [];

        foreach (self::NETWORKS as $slug => [$name, $icon, $address]) {
            if (! in_array($slug, $chosen, true)) {
                continue;
            }

            $links[] = [
                'slug' => $slug,
                'name' => $name,
                'href' => strtr($address, ['{url}' => rawurlencode($url), '{text}' => rawurlencode($text)]),
                // The brand's mark where a module keeps them (torgash.blocks),
                // else a plain pictogram — as {socials} does.
                'mark' => (string) (hook_filter('core.brand.mark', null, ['slug' => $slug, 'class' => 'h-4 w-4'])
                    ?: \Illuminate\Support\Facades\Blade::render('<x-icon :name="$icon" size="sm" />', ['icon' => $icon])),
            ];
        }

        return $links;
    }
}

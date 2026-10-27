<?php

namespace Torgash\Newsletter\Campaigns;

use App\Core\Mail\Letters;
use Torgash\Newsletter\Models\Campaign;
use Torgash\Newsletter\Models\Delivery;
use Torgash\Newsletter\Models\Link;

/**
 * A campaign's letter, written in core's frame (PLAN §41) like every letter
 * of the shop: the registry event `newsletter.campaign` is not editable on
 * Admin → Letters (its words are the campaign's own) but takes the frame,
 * the storefront's theme and its domain from Letters::compose().
 *
 * The body is the campaign's rich text in the reader's language, CLEANED
 * (safe_html — a letter is staff HTML like a description), with
 * `{product:SKU}` alone in its paragraph drawn as a product's card in
 * tables a mail client understands, `{name}` / `{email}` filled. For a real
 * delivery every link goes through the click route (counted, then redirected
 * to the address stored for it) and the letter carries the open pixel and its
 * way out; a test letter keeps its links as they are and counts nothing.
 */
class CampaignLetter
{
    public const EVENT = 'newsletter.campaign';

    /** @return array<string, array<string, mixed>> */
    public static function event(): array
    {
        return [
            self::EVENT => [
                'label' => 'Newsletter campaign',
                'group' => 'Newsletter',
                'audience' => 'customer',
                'editable' => false,
                'subject' => '{subject}',
                'heading' => '{heading}',
                'preheader' => '{preheader}',
                'body' => '{content}',
                'tokens' => fn (array $c) => self::tokens($c),
                'blocks' => fn (array $c) => ['content' => self::content($c)],
                'append' => fn (array $c) => self::append($c),
            ],
        ];
    }

    /**
     * The letter one delivery (or a test) receives.
     *
     * @return array{subject: string, html: string, reply_to: ?string}
     */
    public static function compose(Campaign $campaign, ?Delivery $delivery, string $locale): array
    {
        return Letters::compose(self::EVENT, array_filter([
            'campaign' => $campaign,
            'delivery' => $delivery,
            'storefront' => $campaign->storefront_id ? \App\Models\Storefront::find($campaign->storefront_id) : null,
        ]), $locale);
    }

    /** @return array<string, string> */
    protected static function tokens(array $c): array
    {
        $campaign = $c['campaign'] ?? null;

        if (! $campaign instanceof Campaign) {
            return [];
        }

        $person = self::person($c);
        // "{name}, a gift for you" — the reader's tokens inside the campaign's own lines.
        $fill = fn (?string $line) => strtr(self::withoutName((string) $line, $person['name']), ['{name}' => $person['name'], '{email}' => $person['email']]);

        return [
            'subject' => $fill($campaign->t('subject')),
            'heading' => $fill($campaign->t('heading')),
            'preheader' => $fill($campaign->t('preheader')),
        ] + $person;
    }

    /** @return array{name: string, email: string} */
    protected static function person(array $c): array
    {
        $delivery = $c['delivery'] ?? null;

        if (! $delivery instanceof Delivery) {
            return ['name' => (string) (auth()->user()?->name ?? ''), 'email' => (string) (auth()->user()?->email ?? '')];
        }

        return ['name' => (string) ($delivery->subscriber?->user?->name ?? ''), 'email' => $delivery->email];
    }

    /**
     * A reader with no name (most subscribers have no account): "{name}, the
     * sale is on" must not become ", the sale is on" — the token goes with
     * its comma, and a sentence it started starts with a capital.
     */
    protected static function withoutName(string $text, string $name): string
    {
        if ($name !== '' || ! str_contains($text, '{name}')) {
            return $text;
        }

        $text = (string) preg_replace('/\s*,\s*\{name\}/u', '', $text);   // "Hello, {name}!"
        $text = (string) preg_replace_callback('/\{name\}\s*,?\s*(\p{Ll})?/u', fn ($m) => isset($m[1]) ? mb_strtoupper($m[1]) : '', $text);

        return $text;
    }

    protected static function content(array $c): string
    {
        $campaign = $c['campaign'] ?? null;

        if (! $campaign instanceof Campaign) {
            return '';
        }

        $html = (string) safe_html($campaign->t('body'));
        $person = self::person($c);

        // {products:A,B,C} alone in its paragraph → a grid of tiles; {product:SKU} → one card.
        $html = (string) preg_replace_callback('~(?:<p>\s*)?\{products:([^{}<>]+)\}(?:\s*</p>)?~u', fn ($m) => ProductCard::grid($m[1]), $html);
        $html = (string) preg_replace_callback('~(?:<p>\s*)?\{product:([^{}<>\s]+)\}(?:\s*</p>)?~u', fn ($m) => ProductCard::html(html_entity_decode($m[1])), $html);
        // {name}, {email}: the reader's own, escaped.
        $html = strtr(self::withoutName($html, $person['name']), ['{name}' => e($person['name']), '{email}' => e($person['email'])]);

        // What a mail client needs said on the element itself: no stylesheet reaches it.
        $html = (string) preg_replace('~<img\b(?![^>]*\bstyle=)~i', '<img style="max-width:100%;height:auto;border:0;display:block;margin:12px 0;"', $html);
        $html = (string) preg_replace('~<(h[1-3])>~i', '<$1 style="margin:20px 0 10px;line-height:1.3;color:#0f172a;">', $html);
        $html = (string) preg_replace('~<p>~i', '<p style="margin:0 0 14px;">', $html);

        $delivery = $c['delivery'] ?? null;

        return $delivery instanceof Delivery ? self::tracked($html, $campaign, $delivery) : $html;
    }

    /** Every web address in the letter through the click route — counted, then redirected. */
    protected static function tracked(string $html, Campaign $campaign, Delivery $delivery): string
    {
        return (string) preg_replace_callback('~(<a\b[^>]*\bhref=")(https?://[^"]+)(")~i', function ($m) use ($campaign, $delivery) {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
            $link = Link::for($campaign, $url);

            return $m[1].e(url('/newsletter/c/'.$delivery->token.'/'.$link->id)).$m[3];
        }, $html);
    }

    /** The way out (and, for a real delivery, the open pixel) — what the letter may not lose. */
    protected static function append(array $c): string
    {
        $delivery = $c['delivery'] ?? null;
        $subscriber = $delivery instanceof Delivery ? $delivery->subscriber : null;

        $out = e(__('You receive this letter because you subscribed to the news and offers of {shop_name}.', [], app()->getLocale()));
        $out = str_replace('{shop_name}', e(current_storefront()?->name ?? config('app.name')), $out);

        if ($subscriber) {
            $unsubscribe = route('newsletter.unsubscribe', $subscriber->token).'?d='.$delivery->token;
            $out .= ' <a href="'.e($unsubscribe).'" style="color:#64748b;">'.e(__('Unsubscribe')).'</a>';
        } else {
            $out .= ' <span style="color:#64748b;text-decoration:underline;">'.e(__('Unsubscribe')).'</span>';
        }

        if ($delivery instanceof Delivery) {
            $out .= '<img src="'.e(url('/newsletter/o/'.$delivery->token.'.gif')).'" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">';
        }

        return $out;
    }

    /** The address a mail client's own "Unsubscribe" button posts to (RFC 8058). */
    public static function oneClickUrl(Delivery $delivery): string
    {
        return url('/newsletter/u/'.$delivery->token);
    }
}

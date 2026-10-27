<?php

namespace App\Core\Mail;

use App\Models\MailText;
use Illuminate\Support\Facades\Mail;

/**
 * The one way a letter is written and sent (PLAN §41): the event's words —
 * the shop's own where it wrote them, its module's default otherwise — in
 * the READER's language, inside the one frame every letter shares.
 *
 * Words are plain text with {tokens}: the event's own values (escaped), its
 * BLOCKS (HTML it draws itself: an order's lines, a big code) when a token
 * stands alone on its line, and core's global placeholders ({phone} and the
 * rest). A blank line is a new paragraph. Nobody writes HTML to change a
 * sentence, and nothing typed can inject any.
 */
class Letters
{
    /**
     * Sends a letter. Queued, unless `$now` — a code has to be known to have
     * gone while the customer waits. Returns false when there is nobody to
     * write to or the shop switched the letter off; a failure to SEND throws.
     *
     * @param  string|list<string>  $to
     * @param  array<string, mixed>  $context  models and plain values
     */
    public static function send(string $event, string|array|null $to, array $context = [], ?string $locale = null, bool $now = false): bool
    {
        $definition = MailEvents::find($event);
        // An account may have no email (PLAN §44, 3b): no address, no letter —
        // every caller gets the same quiet "not sent" rather than an error.
        $recipients = array_values(array_filter((array) $to, fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL)));

        if (! $definition || $recipients === [] || ! self::enabled($event)) {
            return false;
        }

        $mail = new EventMail($event, $context, $locale ?? app()->getLocale());
        $pending = Mail::to($recipients);

        $now ? $pending->sendNow($mail) : $pending->queue($mail);

        return true;
    }

    /** A letter to the SHOP: to the addresses it gave for this event. */
    public static function toShop(string $event, array $context = []): bool
    {
        return self::send($event, self::recipients($event), $context, config('app.locale'));
    }

    /** @return list<string> */
    public static function recipients(string $event): array
    {
        $row = self::row($event);

        if ($row && $row->recipientList() !== []) {
            return $row->recipientList();
        }

        $default = MailEvents::find($event)['recipients'] ?? null;

        return is_callable($default) ? array_values((array) $default()) : [];
    }

    public static function enabled(string $event): bool
    {
        $definition = MailEvents::find($event);

        return ($definition['required'] ?? false) || (self::row($event)?->enabled ?? true);
    }

    /**
     * The letter as it will be read: subject and the whole HTML. A `$draft`
     * stands in for the stored words — the editor's preview of text not saved yet.
     *
     * @return array{subject: string, html: string, reply_to: ?string}
     */
    public static function compose(string $event, array $context, string $locale, ?MailText $draft = null): array
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        // The letter is drawn in the THEME of the storefront it is about — the
        // order's, the enquiry's — so a theme overriding mail/frame.blade.php
        // (or a module's block) is the one used, in a queue worker too, where
        // there is no request to have chosen a storefront.
        $themes = app(\App\Core\Themes\ThemeManager::class);
        $storefront = self::storefrontFor($context);
        $previousStorefront = current_storefront();
        $previousTheme = $themes->active();
        $switch = $storefront && $storefront->id !== $previousStorefront?->id;

        // ...and every address in it is on THAT storefront's domain. A queue
        // worker has no request, so url() and route() would answer with
        // APP_URL — the main shop — for an order placed on the second one;
        // the admin changing such an order's status is on the main domain
        // too. The root is put back afterwards, exactly as it was.
        $urls = app('url');
        $previousRoot = (fn () => $this->forcedRoot)->call($urls);
        $root = $switch ? $storefront->url('/') : null;

        if ($switch) {
            app()->instance('torgash.storefront', $storefront);
            $themes->setActive($storefront->theme ?: config('torgash.default_theme'));
        }
        if ($root !== null) {
            $urls->forceRootUrl(rtrim($root, '/'));
        }

        try {
            return self::composeHere($event, $context, $locale, $draft);
        } finally {
            app()->setLocale($previous);

            if ($root !== null) {
                $urls->forceRootUrl($previousRoot);
            }

            if ($switch) {
                $previousStorefront
                    ? app()->instance('torgash.storefront', $previousStorefront)
                    : app()->forgetInstance('torgash.storefront');
                $themes->setActive($previousTheme);
            }
        }
    }

    /**
     * The storefront a letter is about: one handed in as `storefront`, else
     * the first record in the context that belongs to one (an order, an
     * enquiry, a wishlist), else the current one.
     */
    public static function storefrontFor(array $context): ?\App\Models\Storefront
    {
        if (($context['storefront'] ?? null) instanceof \App\Models\Storefront) {
            return $context['storefront'];
        }

        foreach ($context as $value) {
            if ($value instanceof \Illuminate\Database\Eloquent\Model && ($id = $value->getAttribute('storefront_id'))) {
                return \App\Models\Storefront::find($id) ?? current_storefront();
            }
        }

        return current_storefront();
    }

    /** The words of one field, the shop's or the default, in this language. */
    public static function words(string $event, string $field, string $locale, ?MailText $draft = null): string
    {
        $definition = MailEvents::find($event) ?? [];
        $own = ($draft ?? self::row($event))?->own($field, $locale);

        if ($own !== null) {
            return $own;
        }

        $default = $field === 'button' ? ($definition['button']['label'] ?? '') : ($definition[$field] ?? '');

        // In THIS language, not the app's: the editor asks for the default of
        // the language being edited while the admin itself speaks another.
        return $default === '' ? '' : __($default, [], $locale);
    }

    /** @return array{subject: string, html: string, reply_to: ?string} */
    protected static function composeHere(string $event, array $context, string $locale, ?MailText $draft = null): array
    {
        $definition = MailEvents::find($event) ?? [];
        $tokens = self::tokens($definition, $context);
        $blocks = is_callable($definition['blocks'] ?? null) ? (array) $definition['blocks']($context) : [];

        $subject = self::plain(self::words($event, 'subject', $locale, $draft), $tokens);
        $heading = self::plain(self::words($event, 'heading', $locale, $draft), $tokens);
        // The inbox's grey line beside the subject; the heading when none was written.
        $preheader = self::plain(self::words($event, 'preheader', $locale, $draft), $tokens) ?: $heading;
        $body = self::body(self::words($event, 'body', $locale, $draft), $tokens, $blocks);

        $button = null;
        $buttonUrl = self::plain((string) ($definition['button']['url'] ?? ''), $tokens);
        if ($buttonUrl !== '' && filter_var($buttonUrl, FILTER_VALIDATE_URL)) {
            $button = ['label' => self::plain(self::words($event, 'button', $locale, $draft), $tokens), 'url' => $buttonUrl];
        }

        // What the shop may not edit away: a way out of a letter the customer
        // agreed to (a wishlist's "stop these letters").
        $append = is_callable($definition['append'] ?? null) ? (string) $definition['append']($context) : '';

        $html = view('mail.frame', [
            'subject' => $subject,
            'heading' => $heading,
            'preheader' => $preheader,
            'body' => $body,
            'button' => $button,
            'append' => $append,
            'frame' => Frame::settings(),
            'footer' => self::body((string) Frame::settings()['footer'], $tokens, []),
            'topline' => self::plain((string) Frame::settings()['topline'], $tokens),
        ])->render();

        $replyTo = is_callable($definition['reply_to'] ?? null) ? $definition['reply_to']($context) : null;

        return ['subject' => $subject, 'html' => $html, 'reply_to' => filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : null];
    }

    /** @return array<string, string> */
    protected static function tokens(array $definition, array $context): array
    {
        $storefront = current_storefront();
        $own = is_callable($definition['tokens'] ?? null) ? (array) $definition['tokens']($context) : [];

        return array_map(fn ($value) => (string) $value, $own + [
            'shop_name' => $storefront?->name ?? config('app.name'),
            'shop_url' => url('/'),
        ]);
    }

    /** A line of plain text: tokens filled, core placeholders expanded, no HTML. */
    protected static function plain(string $text, array $tokens): string
    {
        $text = preg_replace_callback('/\{([a-z0-9_]+)\}/i', fn ($m) => array_key_exists($m[1], $tokens) ? $tokens[$m[1]] : $m[0], $text);

        return trim(html_entity_decode(strip_tags(app(\App\Core\Content\Placeholders::class)->expand($text, [], escape: false))));
    }

    /**
     * Paragraphs of escaped text with tokens filled; a line holding only a
     * block's token becomes that block's HTML.
     *
     * @param  array<string, string>  $tokens
     * @param  array<string, string>  $blocks
     */
    protected static function body(string $text, array $tokens, array $blocks): string
    {
        $html = [];

        foreach (preg_split('/\R\s*\R/', trim($text)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (preg_match('/^\{([a-z0-9_]+)\}$/i', $paragraph, $m) && array_key_exists($m[1], $blocks)) {
                $html[] = (string) $blocks[$m[1]];

                continue;
            }

            $escaped = e($paragraph);
            $escaped = preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($m) use ($tokens, $blocks) {
                if (array_key_exists($m[1], $tokens)) {
                    return e($tokens[$m[1]]);
                }

                return array_key_exists($m[1], $blocks) ? '' : $m[0];
            }, $escaped);

            // Core's placeholders ({phone}, {icon_…}) last; they escape what they insert.
            $escaped = app(\App\Core\Content\Placeholders::class)->expand($escaped);

            // A paragraph that was only an empty token ("{moderation}" on a
            // published review) is not drawn as an empty gap.
            if (trim(strip_tags($escaped)) === '') {
                continue;
            }

            $html[] = '<p style="margin:0 0 14px;">'.nl2br($escaped, false).'</p>';
        }

        return implode("\n", $html);
    }

    public static function row(string $event): ?MailText
    {
        // Not memoised across requests: a static would outlive the request
        // under a worker. A letter is a rare event; one query is cheap.
        return MailText::where('event', $event)->first();
    }
}

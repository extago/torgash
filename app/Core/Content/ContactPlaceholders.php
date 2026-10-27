<?php

namespace App\Core\Content;

use App\Core\Support\Icons;

/**
 * The storefront's contact channels as {token} substitutions.
 *
 * This was `Torgash\Contacts\PanelRenderer` until the module was dissolved. It
 * is core now for one reason: the DATA is core. The values live in
 * `storefronts.contacts` and are edited in the core storefront form, and a
 * saved HTML block containing {phone} would have silently degraded into the
 * literal text "{phone}" the moment someone uninstalled the module that
 * happened to register the token. A module you cannot uninstall without
 * breaking other modules' content is not a module.
 *
 * What the module owned that was genuinely optional — the header dropdown's
 * markup — is now a content block in `storefront.header.after_search`, so the
 * merchant edits it where they edit everything else on the page.
 *
 * Composite tokens ({messengers}, {email_row}, …) expand to nothing when their
 * data is empty, so a snippet written for a fully-populated store still renders
 * cleanly on one that only filled in a phone number.
 */
class ContactPlaceholders
{
    public function value(string $key): string
    {
        return current_storefront()?->contact($key) ?? '';
    }

    public function phone(): string
    {
        return $this->value('phone');
    }

    /** tel: needs the bare number — strip everything a human reads it by. */
    public function phoneLink(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        return 'tel:'.$digits;
    }

    /**
     * Registers the tokens with the global registry. Resolvers are lazy, so a
     * page whose content uses none of them does no work at all.
     */
    public function register(Placeholders $placeholders): void
    {
        $group = 'contacts';
        $simple = [
            'phone' => 'Phone',
            'email' => 'Email',
            'hours' => 'Opening hours',
            'address' => 'Legal address',
        ];

        foreach ($simple as $key => $label) {
            $placeholders->register($key, [
                'label' => $label,
                'group' => $group,
                'resolve' => fn () => $this->value($key),
                'example' => $this->value($key) ?: null,
            ]);
        }

        // Who the shop IS and what it is charging in — not contacts, but the
        // same kind of fact and the same owner, and an SEO template needs both
        // ("Buy {product} in {storefront}").
        $placeholders->register('storefront', [
            'label' => 'Shop name',
            'group' => 'shop',
            'resolve' => fn () => (string) (current_storefront()?->name ?? ''),
        ]);

        $placeholders->register('currency', [
            'label' => 'Currency code',
            'group' => 'shop',
            'resolve' => fn () => current_currency(),
        ]);

        // The phone and the email as the footer draws them: pressed, they offer
        // "Copy" and "Call" / "Write" (core's contact-actions popover). Empty
        // when the contact is, so `[<div>…{phone_actions}…</div>]` drops the
        // whole row on a shop that never gave one.
        foreach (['phone' => 'phone_actions', 'email' => 'email_actions'] as $kind => $token) {
            $placeholders->register($token, [
                'label' => $kind === 'phone' ? 'Phone with "Copy | Call"' : 'Email with "Copy | Write"',
                'group' => $group,
                'raw' => true,
                'resolve' => fn () => $this->value($kind) === '' ? '' : trim(\Illuminate\Support\Facades\Blade::render(
                    '<x-contact-actions :value="$value" :kind="$kind" :class="$class" />',
                    ['value' => $this->value($kind), 'kind' => $kind, 'class' => $kind === 'phone' ? 'font-medium' : ''],
                )),
            ]);
        }

        $placeholders->register('phone_link', [
            'label' => 'Phone as a tel: link',
            'group' => $group,
            'resolve' => fn () => $this->phoneLink($this->phone()),
        ]);

        $placeholders->register('callback_link', [
            'label' => 'Callback link (falls back to dialling)',
            'group' => $group,
            'resolve' => function () {
                $callback = $this->value('callback_url');

                if ($callback !== '') {
                    return $callback;
                }

                // Dialling is the honest fallback, and it is what a shop with
                // no callback form gets. A module that HAS one (torgash.enquiries)
                // answers this filter with the anchor that opens it — core must
                // not know that module's name, and content written with
                // {callback_link} keeps working whether it is installed or not.
                return hook_filter('core.contacts.callback', $this->phoneLink($this->phone()));
            },
        ]);

        // Markup-producing tokens declare raw: true, so the choice not to escape
        // is visible here rather than implicit at the render site.
        $placeholders->register('messengers', [
            'label' => 'Messenger buttons',
            'group' => $group,
            'raw' => true,
            'resolve' => fn () => $this->messengers(),
        ]);

        // The lists (Storefront::CONTACT_LISTS). {phones} is every number
        // AFTER the main one, so a panel that already prints {phone} large
        // adds the rest under it; {socials} is a row of round marks.
        $placeholders->register('phones', [
            'label' => 'More phone numbers, one row each',
            'group' => $group,
            'raw' => true,
            'resolve' => fn () => $this->phones(),
        ]);

        $placeholders->register('socials', [
            'label' => 'Social network links as icons',
            'group' => $group,
            'raw' => true,
            'resolve' => fn () => $this->socials(),
        ]);

        $placeholders->register('email_row', [
            'label' => 'Email row with icon',
            'group' => $group,
            'raw' => true,
            'resolve' => fn () => $this->value('email') === ''
                ? '' : $this->row('mail', 'mailto:'.$this->value('email'), $this->value('email')),
        ]);

        $placeholders->register('hours_row', [
            'label' => 'Hours row with icon',
            'group' => $group,
            'raw' => true,
            'resolve' => fn () => $this->value('hours') === ''
                ? '' : $this->row('clock', null, $this->value('hours')),
        ]);

        // The generic UI icons used to be registered here, with a note saying
        // they should move out the moment a second provider appeared. It has:
        // see IconPlaceholders, where the list is a setting rather than four
        // names in a loop. `icon()` below stays — the contact rows draw their
        // own icons whether or not the shop exposed any as tokens.

        $placeholders->register('label_phone', [
            'label' => 'Translated word "Phone"',
            'group' => $group,
            'resolve' => fn () => __('Phone'),
        ]);

        $placeholders->register('label_callback', [
            'label' => 'Translated "Request a callback"',
            'group' => $group,
            'resolve' => fn () => __('Request a callback'),
        ]);
    }

    protected function messengers(): string
    {
        $links = array_filter([
            'Telegram' => $this->value('telegram'),
            'WhatsApp' => $this->value('whatsapp'),
            'Viber' => $this->value('viber'),
        ]);

        if ($links === []) {
            return '';
        }

        // One row of equal tiles — the mark in the messenger's colour over
        // its name — rather than outlined buttons: three of those did not fit
        // a panel's width and the third went to a row of its own.
        $buttons = '';
        foreach ($links as $name => $url) {
            $slug = strtolower($name);
            $mark = hook_filter('core.brand.mark', null, ['slug' => $slug, 'class' => 'h-5 w-5']) ?: $this->icon('message-circle');
            $buttons .= '<a class="torgash-messenger" target="_blank" rel="noopener" href="'.e($url).'" data-social="'.e($slug).'">'
                .'<span class="torgash-messenger-mark">'.$mark.'</span><span>'.e($name).'</span></a>';
        }

        return '<div class="torgash-messengers" style="--messengers: '.count($links).'">'.$buttons.'</div>';
    }

    /** @return list<array<string, string>> */
    public function list(string $key): array
    {
        return current_storefront()?->contactList($key) ?? [];
    }

    /**
     * Each number drawn as the header panel draws the main one — a tinted
     * square with the phone, the caption small above, the number large — so
     * the second line of a panel does not look like a footnote to the first.
     */
    protected function phones(): string
    {
        $rows = '';
        foreach ($this->list('phones') as $phone) {
            $rows .= '<div class="flex items-center gap-3">'
                .'<span class="h-10 w-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">'.$this->icon('phone').'</span>'
                .'<span class="min-w-0">'
                .'<span class="block text-xs uppercase tracking-wide opacity-50 truncate">'.e($phone['label'] !== '' ? $phone['label'] : __('Phone')).'</span>'
                .'<a class="block text-lg font-bold whitespace-nowrap" href="'.e($this->phoneLink($phone['number'])).'">'.e($phone['number']).'</a>'
                .'</span></div>';
        }

        return $rows === '' ? '' : '<div class="flex flex-col gap-3">'.$rows.'</div>';
    }

    protected function socials(): string
    {
        $links = '';
        foreach ($this->list('socials') as $social) {
            $name = $social['network'] !== '' ? ucfirst($social['network']) : parse_url($social['url'], PHP_URL_HOST);
            // A brand's mark is a trademark core does not ship: a module that
            // has the marks answers (torgash.blocks), else a plain globe.
            $mark = hook_filter('core.brand.mark', null, ['slug' => $social['network'], 'class' => 'h-4 w-4']) ?: $this->icon('globe');
            $links .= '<a class="torgash-social btn btn-sm btn-circle btn-ghost" target="_blank" rel="noopener" href="'
                .e($social['url']).'" title="'.e((string) $name).'" aria-label="'.e((string) $name).'" data-social="'.e($social['network']).'">'
                .$mark.'</a>';
        }

        return $links === '' ? '' : '<div class="flex flex-wrap items-center gap-1.5">'.$links.'</div>';
    }

    protected function row(string $icon, ?string $href, string $text): string
    {
        $body = $href === null
            ? e($text)
            : '<a class="link link-hover" href="'.e($href).'">'.e($text).'</a>';

        return '<div class="flex items-center gap-2 text-sm opacity-70">'
            .$this->icon($icon).$body.'</div>';
    }

    /** Inline Lucide markup — the content is a string, so <x-icon> is not available in it. */
    protected function icon(string $name): string
    {
        $markup = Icons::markup($name);

        return $markup === null
            ? ''
            : str_replace('<svg ', '<svg class="h-4 w-4 shrink-0" aria-hidden="true" ', $markup);
    }
}

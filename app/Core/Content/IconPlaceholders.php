<?php

namespace App\Core\Content;

use App\Core\Settings\CoreSettings;
use App\Core\Support\Icons;

/**
 * `{icon_gem}` in admin-authored HTML — for a chosen handful, not for all 2035.
 *
 * Admin HTML has no way to draw an icon: the component tag compiler does not
 * run on database content, and it must not. A token is the way in.
 *
 * The list is a SETTING rather than the whole pack, because the placeholder
 * picker is a menu a human reads: two thousand entries in it is the same as
 * none. Adding one is typing its name — no deploy, no developer — and the
 * names are lucide.dev's own, which is the same vocabulary the templates use.
 *
 * A module adds its own through `core.icon_tokens` (a carrier module wanting
 * its parcel icon available to shop content), so the shop's list and the
 * modules' do not have to be the same list.
 *
 * An unknown name registers nothing at all rather than a token that renders
 * empty: an admin who mistyped sees `{icon_gemm}` standing in the page, which
 * is the same "a typo should be visible" rule the rest of the registry follows.
 */
class IconPlaceholders
{
    /**
     * Enough to write a contacts panel and a price note without touching the
     * setting — the four the contact tokens always needed, plus the ones every
     * shop's own snippets reach for first.
     */
    public const DEFAULTS = 'phone, mail, clock, chevron-down, gem, truck, shield-check, badge-percent, info, star';

    public function register(Placeholders $placeholders): void
    {
        // Any icon of the pack by its lucide.dev name, as ONE entry of the
        // picker ({icon:star}) — the list below stays the short menu a human
        // reads; this is for HTML written by hand, a theme's blocks above all.
        $placeholders->register('icon', [
            'label' => 'Any icon, by its lucide.dev name',
            'group' => 'icons',
            'raw' => true,
            'argument' => ['label' => 'name', 'example' => 'star'],
            'resolve' => fn (array $context = [], ?string $name = null) => $name !== null && preg_match('/^[a-z0-9-]+$/', trim($name))
                ? $this->markup(trim($name))
                : '',
        ]);

        foreach ($this->names() as $name) {
            $placeholders->register('icon_'.$name, [
                'label' => ['en' => 'Icon: '.$name, 'ru' => 'Иконка: '.$name],
                'group' => 'icons',
                // Markup by definition — the registry escapes everything else.
                'raw' => true,
                'resolve' => fn () => $this->markup($name),
            ]);
        }
    }

    /**
     * The icons this shop made available, cleaned of typos and duplicates.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $configured = (string) setting(CoreSettings::ID, 'icon_tokens', self::DEFAULTS);

        $names = collect(preg_split('/[\s,]+/', $configured))
            ->merge(hook_filter('core.icon_tokens', []))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            // Checked against the vendored pack: a name that is not there would
            // otherwise become a token that always renders nothing.
            ->filter(fn (string $name) => Icons::exists($name));

        return $names->values()->all();
    }

    protected function markup(string $name): string
    {
        $markup = Icons::markup($name);

        return $markup === null
            ? ''
            : str_replace('<svg ', '<svg class="h-4 w-4 shrink-0" aria-hidden="true" ', $markup);
    }
}

<?php

namespace Torgash\Blocks;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Torgash\Blocks\Models\Block;

class BlocksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ZoneBlocks::class);
        \App\Core\Support\PerRequest::forget($this->app, ZoneBlocks::class);
    }

    public function boot(): void
    {
        $this->registerBuiltInTypes();
        $this->subscribeToZones();

        // A theme preset applied WITH its layout (PLAN §37): core hands over
        // zone => blocks and knows nothing more; this module lays them out.
        hook_listen('core.theme.preset_blocks', function (array $context) {
            foreach ((array) ($context['zones'] ?? []) as $zone => $blocks) {
                app(ThemeDefaults::class)->replaceWith((string) $zone, (array) $blocks);
            }
        });

        Route::middleware(['web', 'auth', 'can:blocks.manage'])
            ->prefix('admin/blocks')
            ->name('admin.blocks.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }

    /**
     * The two types the module ships itself. Everything else is expected to
     * come from other modules — a catalog "product grid", a media "banner" —
     * registered the same way from their own providers, which is what keeps
     * this module from having to know about them.
     */
    protected function registerBuiltInTypes(): void
    {
        // Sharing is not a pages feature and not a catalogue one — it is a
        // button on whatever page a zone happens to be on, so it ships here
        // with the other generic types rather than in the module that asked
        // for it first. The template reads the current URL; see its docblock
        // for why there are no brand logos.
        BlockTypes::register('share', [
            'description' => 'Buttons that share the page in messengers and social networks.',
            'fits' => ['wide', 'narrow'],
            'label' => 'Share buttons',
            'icon' => 'share-2',
            'view' => 'torgash.blocks::blocks.share',
            'schema' => [
                [
                    'key' => 'heading',
                    'type' => 'text',
                    'translatable' => true,
                    'label' => 'Text before the buttons',
                    'help' => 'Optional — "Share:".',
                ],
                [
                    'key' => 'telegram',
                    'type' => 'checkbox',
                    'default' => true,
                    'label' => 'Telegram',
                ],
                [
                    'key' => 'whatsapp',
                    'type' => 'checkbox',
                    'default' => true,
                    'label' => 'WhatsApp',
                ],
                [
                    'key' => 'viber',
                    'type' => 'checkbox',
                    'default' => false,
                    'label' => 'Viber',
                ],
                [
                    'key' => 'vk',
                    'type' => 'checkbox',
                    'default' => false,
                    'label' => 'VK',
                ],
                [
                    'key' => 'facebook',
                    'type' => 'checkbox',
                    'default' => false,
                    'label' => 'Facebook',
                ],
                [
                    'key' => 'x',
                    'type' => 'checkbox',
                    'default' => false,
                    'label' => 'X (Twitter)',
                ],
                [
                    'key' => 'copy_link',
                    'type' => 'checkbox',
                    'default' => true,
                    'label' => 'Copy link',
                    'help' => 'Needs https — the clipboard API does not exist on a plain http page.',
                ],
            ],
        ]);

        BlockTypes::register('rich', [
            'description' => 'Formatted text with headings, lists, links and pictures — written in the visual editor.',
            'fits' => ['wide', 'narrow', 'drawer'],
            'label' => 'Text',
            'icon' => 'type',
            'view' => 'torgash.blocks::blocks.rich',
            'schema' => [
                [
                    'key' => 'heading',
                    'type' => 'text',
                    'translatable' => true,
                    'label' => 'Heading',
                    'help' => 'Optional. Leave empty for body text only.',
                ],
                [
                    'key' => 'body',
                    'type' => 'textarea',
                    'translatable' => true,
                    'label' => 'Text',
                ],
                [
                    'key' => 'align',
                    'type' => 'select',
                    'label' => 'Alignment',
                    'default' => 'start',
                    'options' => [
                        ['value' => 'start', 'label' => 'Left'],
                        ['value' => 'center', 'label' => 'Centered'],
                    ],
                ],
            ],
        ]);

        // The pieces of the phone menu (PLAN §38, storefront.mobile.menu) —
        // blocks so a shop moves, drops or restyles them like any other. Each
        // is general enough to stand elsewhere too: an account row, a list of
        // links, the language and currency pickers.
        BlockTypes::register('account', [
            'description' => 'Sign-in for a guest, the name and the account links for a customer.',
            'fits' => ['inline', 'drawer', 'narrow'],
            'label' => 'Account',
            'icon' => 'circle-user',
            'view' => 'torgash.blocks::blocks.account',
            'schema' => [
                ['key' => 'note', 'type' => 'text', 'translatable' => true, 'label' => 'Line for a guest',
                    'help' => 'Under "Sign in | Register", e.g. what an account gives.'],
                ['key' => 'style', 'type' => 'select', 'default' => 'dark', 'label' => 'Colour',
                    'options' => [
                        ['value' => 'dark', 'label' => 'Dark'],
                        ['value' => 'light', 'label' => 'Light'],
                    ]],
            ],
        ]);

        BlockTypes::register('links', [
            'description' => 'A short list of links typed by hand, with icons — as rows, a line or tiles.',
            'fits' => ['wide', 'narrow', 'inline', 'drawer'],
            'label' => 'List of links',
            'icon' => 'list',
            'view' => 'torgash.blocks::blocks.links',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading'],
                ['key' => 'items', 'type' => 'textarea', 'translatable' => true, 'label' => 'Links',
                    'help' => 'One per line: Label | /address | icon | a small line under it. Only the first two are needed; the icon is a lucide.dev name. Start a line with ! to set it off in the accent colour.'],
                ['key' => 'style', 'type' => 'select', 'default' => 'rows', 'label' => 'Look',
                    'options' => [
                        ['value' => 'rows', 'label' => 'Rows with icons'],
                        ['value' => 'plain', 'label' => 'Plain links'],
                    ]],
            ],
        ]);

        BlockTypes::register('locale', [
            'description' => "Switches of the storefront's language and currency.",
            'fits' => ['inline', 'drawer'],
            'label' => 'Language and currency',
            'icon' => 'languages',
            'view' => 'torgash.blocks::blocks.locale',
            'schema' => [
                ['key' => 'currencies', 'type' => 'checkbox', 'default' => true, 'label' => 'Offer the currencies too'],
            ],
        ]);

        // The footer's pieces that carry data or behaviour (the owner's
        // design, 2026-10-01). What is only markup — a signature, a rating
        // plate, the advantages, social cards — is an HTML block in the
        // theme's `default_blocks`, with {year}, {logo_dark}, {icon:…} and
        // {brand:…}: the owner's call, a block type per line of markup is
        // more types than anybody needs.





        BlockTypes::register('payments', [
            'description' => 'The payment systems you take, as a row of small marks.',
            'fits' => ['wide', 'narrow'],
            'label' => 'Payment logos',
            'icon' => 'credit-card',
            'view' => 'torgash.blocks::blocks.payments',
            'schema' => [
                ['key' => 'items', 'type' => 'textarea', 'label' => 'Marks',
                    'help' => 'One per line: visa, mastercard, applepay, googlepay, samsungpay, paypal, stripe, klarna, alipay, americanexpress — or your own as Name | /storage/…/logo.svg (Mir, SBP and local banks come this way).'],
            ],
        ]);

        // {mark:telegram}, {mark:visa} — a brand's mark (not {brand}: that is the product's brand, catalog's token) (Simple Icons, CC0)
        // for HTML written by hand: a footer's social cards, a payment row.
        app(\App\Core\Content\Placeholders::class)->register('mark', [
            'label' => 'The mark of a brand (telegram, vk, visa, applepay…)',
            'group' => 'icons',
            'raw' => true,
            'argument' => ['label' => 'brand', 'example' => 'telegram'],
            'resolve' => fn (array $context = [], ?string $slug = null) => $slug ? (string) BrandIcons::svg(strtolower(trim($slug)), 'h-4 w-4') : '',
        ]);

        // Core's {socials} asks for a network's mark here: the marks are
        // trademarks this module keeps, core draws a globe without it.
        hook_listen('core.brand.mark', fn (?string $svg, array $context) => $svg ?? (($context['slug'] ?? '') !== '' ? BrandIcons::svg((string) $context['slug'], (string) ($context['class'] ?? 'h-4 w-4')) : null));

        BlockTypes::register('html', [
            'description' => 'Your own markup, printed as it is — a counter, a video, a widget. Placeholders work in it.',
            'fits' => ['wide', 'narrow', 'inline', 'drawer'],
            'label' => 'Raw HTML',
            'icon' => 'code-xml',
            'view' => 'torgash.blocks::blocks.html',
            'schema' => [
                [
                    'key' => 'html',
                    'type' => 'html',
                    'translatable' => true,
                    'label' => 'HTML',
                    // Raw HTML is rendered unescaped, so writing it is the same
                    // trust level as editing a template file — a separate
                    // permission from "may arrange blocks", on purpose.
                    'permission' => 'blocks.html',
                    'help' => 'Rendered as-is, without escaping. Only grant this permission to people you would also give template access.',
                ],
                [
                    // The data-driven counterpart to the manual on/off switches:
                    // a block whose whole point is one value (a phone panel, an
                    // opening-hours strip) should disappear by itself on a store
                    // that never filled that value in, without an admin having
                    // to notice and switch it off. The chrome around the token
                    // is what makes this necessary — the block is not "empty"
                    // just because {phone} resolved to nothing.
                    'key' => 'hide_if_empty',
                    'type' => 'text',
                    'label' => 'Hide when this variable is empty',
                    'help' => 'Optional. One variable name, e.g. {phone}. The whole block renders nothing when it resolves to an empty value.',
                ],
            ],
        ]);
    }

    /**
     * A listener for every zone a block may be drawn in: every zone that has
     * a block at all (on or off) and every zone declared when the module
     * boots. The listener reads the blocks AT REQUEST TIME (ZoneBlocks): a
     * persistent worker boots once, and blocks read at boot stayed on the
     * storefront until it was restarted — a zone switched off, a block edited
     * or added, all invisible on a live shop. An empty zone costs one array
     * lookup; the blocks are one query per page, the first time a zone asks.
     *
     * A block put into a zone that had no listener when the workers booted
     * gets them restarted by the Block model.
     *
     * Wrapped defensively: boot() also runs during `module:install`, before
     * this module's own migration has created the table. That case is handled
     * by catching the failed SELECT rather than by asking Schema::hasTable()
     * first — the check is a full information_schema round trip on EVERY
     * request, paid forever to guard a state that exists for one command.
     */
    protected function subscribeToZones(): void
    {
        try {
            $zones = Block::query()->distinct()->pluck('zone')->all();
        } catch (Throwable) {
            return;
        }

        $zones = array_unique([...$zones, ...array_keys(app(\App\Core\Zones\ZoneRegistry::class)->all())]);

        foreach ($zones as $zone) {
            // Resolved inside the listener, never captured: the listener
            // outlives the request under a persistent worker.
            hook_listen((string) $zone, function (string $value, array $context) use ($zone) {
                $blocks = app(ZoneBlocks::class)->in((string) $zone);

                return $blocks->isEmpty() ? $value : $value.app(ZoneRenderer::class)->render($blocks, $context);
            });
        }
    }
}

<?php

namespace App\Core\Settings;

use App\Core\Content\IconPlaceholders;
use App\Core\Currency\RateSources;
use App\Core\Modules\ModuleManifest;
use App\Core\Seo\AgentLists;

/**
 * Settings that belong to the shop itself rather than to any module.
 *
 * Modules declare theirs in manifest.json; core has no manifest, so its
 * definitions live here in the same shape and are rendered by the same screen
 * through a synthetic manifest. The store id is "core", so `setting('core',
 * 'weight_unit')` reads them like any other.
 *
 * The bar for putting something here is high: it must be data core itself
 * owns, needed by more than one module, and meaningless to uninstall. Units of
 * measure qualify — a product has a weight whether or not delivery is
 * installed, and the catalogue may not read a shipping setting to render its
 * own form.
 */
class CoreSettings
{
    public const ID = 'core';

    /**
     * Core's own permissions, in a manifest's "permissions" shape so the
     * permission-group screen lists them beside the modules' without a special
     * case. They are created by CoreSeeder, not by an install — core is never
     * installed or uninstalled.
     *
     * @return list<array{name: string, label: array<string,string>}>
     */
    public static function permissions(): array
    {
        return [
            [
                'name' => 'admin.access',
                'label' => 'Sign in to the admin panel',
            ],
            [
                'name' => 'themes.manage',
                'label' => 'Choose and configure storefront themes',
            ],
            [
                'name' => 'staff.manage',
                'label' => 'Manage administrators and permission groups',
            ],
            [
                // Accounts are people's data and deleting one cannot be
                // undone, so the customer screens are not simply "in the
                // admin" — a warehouse manager has no business there.
                'name' => 'customers.manage',
                'label' => 'Manage customers and customer groups',
            ],
            [
                // A storefront's domains decide which site a visitor reaches
                // and its default currency is what every stored amount MEANS,
                // so it is not simply "in the admin" either.
                'name' => 'storefronts.manage',
                'label' => 'Manage storefronts: domains, languages, currencies, contacts',
            ],
            [
                // Every module's settings on one screen: what the shop does
                // at checkout, what it sends, what it shows.
                'name' => 'settings.manage',
                'label' => 'Change the settings of the shop and its modules',
            ],
            [
                // A rate reprices every product priced in that currency.
                'name' => 'currencies.manage',
                'label' => 'Manage currencies and exchange rates',
            ],
            [
                'name' => 'languages.manage',
                'label' => 'Manage the languages of the shop',
            ],
            [
                // Old addresses and where they lead: a wrong row sends real
                // visitors and crawlers somewhere else.
                'name' => 'redirects.manage',
                'label' => 'Manage redirects',
            ],
            [
                // Retrying a failed job re-sends a letter; cancelling stops an import.
                'name' => 'tasks.manage',
                'label' => 'See and manage background tasks and failed jobs',
            ],
            [
                // What the shop says to its customers in writing.
                'name' => 'mail.manage',
                'label' => 'Edit the letters the shop sends',
            ],
            [
                // Writes .env; switching where sessions live signs every
                // customer out.
                'name' => 'cache.manage',
                'label' => 'Choose where the cache and sessions are kept, and clear the cache',
            ],
            [
                // What the server is, and the backups: one is every
                // customer's data in a single file (PLAN §45).
                'name' => 'system.manage',
                'label' => 'See the system page and make, download and delete backups',
            ],
            [
                // Installing, switching off or uninstalling a module changes
                // what the shop IS — uninstalling drops its tables — so it is
                // not simply "in the admin". Uploading a module's code is more
                // still, and is kept to the built-in Administrator group.
                'name' => 'modules.manage',
                'label' => 'Install, switch and remove modules',
            ],
        ];
    }

    /** @return list<array<string, mixed>> the same shape as a manifest's "settings" */
    public static function definitions(): array
    {
        return [
            [
                'key' => '_g_units',
                'type' => 'group',
                'label' => 'Units of measure',
            ],
            [
                'key' => 'weight_unit',
                'type' => 'select',
                'default' => 'kg',
                'options' => [
                    'kg' => 'Kilograms',
                    'g' => 'Grams',
                    'lb' => 'Pounds',
                ],
                'label' => 'Weight',
                'help' => 'What weights are typed and shown in — product weights, shipping bands, carrier requests. Stored weights are always whole grams, so changing this converts nothing and loses nothing.',
            ],
            [
                'key' => 'length_unit',
                'type' => 'select',
                'default' => 'cm',
                'options' => [
                    'cm' => 'Centimetres',
                    'mm' => 'Millimetres',
                    'in' => 'Inches',
                ],
                'label' => 'Dimensions',
                'help' => 'Same for length, width and height. Stored in whole millimetres.',
            ],
            [
                'key' => '_g_accounts',
                'type' => 'group',
                'label' => 'Customer accounts',
            ],
            [
                'key' => 'account_contact',
                'type' => 'select',
                'default' => 'email',
                'options' => [
                    'email' => 'Email',
                    'phone' => 'Phone',
                    'either' => 'Email or phone — either will do',
                    'both' => 'Both email and phone',
                ],
                'label' => 'A customer account must have',
                'help' => 'What registration asks for and what a customer may not remove from their account. A phone is always confirmed by a code — that is what signs in with it — so the phone choices work only while the shop can send a code to a phone (Customers → Code delivery); without one, an email is required. With "Phone" an email is optional: without it the customer simply gets no letters.',
                'keywords' => ['en' => 'registration required email phone contact', 'ru' => 'регистрация обязательный email телефон контакт почта'],
            ],
            [
                'key' => 'sign_in_by_code',
                'type' => 'checkbox',
                'default' => false,
                'label' => 'Let customers sign in with a code instead of a password',
                'help' => 'A "Sign in with a code" link beside the password: the customer types their email and gets a six-digit code. Getting back into an account with a lost password works the same way and is always on.',
                'keywords' => ['en' => 'one-time password otp login passwordless', 'ru' => 'одноразовый код вход без пароля'],
            ],
            [
                'key' => 'verify_forms',
                'type' => 'checkboxes',
                'default' => [],
                // Filled in by screenDefinitions(): each form is registered by
                // its owner through `core.verified_forms`.
                'options' => [],
                'label' => 'Confirm the contact by a code on these forms',
                'help' => 'A guest gets a code to their phone — or their email where the shop cannot send an SMS — and the form is sent only once it is typed back. Stops orders and callbacks with somebody else\'s number. A contact confirmed once is not asked again in the same browser for 90 days. Signed-in customers are never asked: the account already says who they are.',
                'keywords' => ['en' => 'spam fake orders callback verification sms', 'ru' => 'спам фейковые заказы обратный звонок подтверждение смс'],
            ],
            [
                'key' => 'default_country',
                'type' => 'text',
                'default' => '',
                'label' => 'Country a phone number is read in',
                'help' => 'Two letters (UA, PL, DE, US). A number typed without its + and country code is read as this country\'s — unless the customer chose a town, then the town\'s country. Empty: a number must be typed with its code. It is also the country the town window opens on.',
                'keywords' => ['en' => 'phone country code international', 'ru' => 'телефон код страны'],
            ],
            [
                'key' => '_g_time',
                'type' => 'group',
                'label' => 'Time',
            ],
            [
                'key' => 'timezone',
                'type' => 'select',
                'default' => '',
                // Every zone PHP knows, by name: a short list would be a guess
                // about where shops are.
                'options' => ['' => 'UTC'] + array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
                'label' => 'The shop\'s time zone',
                'help' => 'The clock of the whole shop: the time an order was placed, the dates in letters, what "from 18:00" means in a condition. Times already stored keep their hour — set it before the first orders.',
                'keywords' => ['en' => 'time zone clock hours calendar', 'ru' => 'часовой пояс время часы календарь'],
            ],
            [
                'key' => '_g_admin',
                'type' => 'group',
                'label' => 'Admin panel',
            ],
            [
                // The icon in the browser's tab of every admin page and of
                // the sign-in page — the engine's own mark until the shop
                // uploads its own, so an admin tab is never a blank page
                // icon among the others.
                'key' => 'admin_icon',
                'type' => 'image',
                'default' => '',
                'label' => 'Admin panel icon',
                'help' => 'In the browser\'s tab of every admin page, beside its name — the way to tell this shop\'s panel from the others open. A square picture: an SVG, or a PNG of 64 px or more. Empty — the engine\'s own mark.',
            ],
            [
                'key' => '_g_content',
                'type' => 'group',
                'label' => 'Content',
            ],
            [
                'key' => 'icon_tokens',
                'type' => 'textarea',
                'rows' => 3,
                'default' => IconPlaceholders::DEFAULTS,
                'label' => 'Icons available in content as {icon_…}',
                'help' => 'Names from lucide.dev, separated by commas — all 2035 are already installed, this is only which of them appear in the placeholder list. Add one and it can be written into any HTML block as {icon_name}. A name that does not exist is ignored.',
            ],
            [
                'key' => \App\Core\Content\Share::SETTING,
                'type' => 'checkboxes',
                'default' => \App\Core\Content\Share::DEFAULTS,
                'options' => \App\Core\Content\Share::options(),
                'label' => 'Share to',
                'help' => 'Where the "Share" button of an article or a page offers to send it. Each is a plain link to the network\'s own share address, so nothing is loaded from them until somebody presses one. "Copy the link" is always there; on a phone the system\'s own share sheet comes first.',
                'keywords' => ['en' => 'share social networks messengers telegram whatsapp', 'ru' => 'поделиться соцсети мессенджеры телеграм вотсап'],
            ],
            [
                'key' => \App\Core\Content\SafeHtml::SETTING,
                'type' => 'textarea',
                'rows' => 4,
                'default' => "youtube.com\nyoutube-nocookie.com\nplayer.vimeo.com\nvk.com\nvkvideo.ru\nrutube.ru\ngoogle.com\nyandex.ru",
                'label' => 'Sites whose frames a text may show',
                'help' => 'One per line, and a site covers its subdomains. A video or a map from these sites stays in a description, a page or an article; a frame from anywhere else is removed when the text is shown, together with scripts, forms and event handlers — scripts belong in Code snippets.',
                'keywords' => ['en' => 'iframe frame embed video map youtube html script', 'ru' => 'iframe фрейм встроить видео карта youtube html скрипт'],
            ],
            [
                'key' => \App\Core\Http\Csp::SETTING,
                'type' => 'select',
                'default' => 'enforce',
                'options' => [
                    'enforce' => "Enforce: only the shop's own scripts run",
                    'report' => 'Report only: everything runs, what would be blocked is logged',
                    'off' => 'Off',
                ],
                'label' => 'Script policy (CSP)',
                'help' => 'Which scripts a page may run. Enforced, a script that got into the page any other way than through the engine or Code snippets does not run — the last line of defence if harmful markup ever slips into a text. "Report only" is for a module written before the rule: the page works, and what would be blocked is written to storage/logs/csp-*.log.',
                'keywords' => ['en' => 'csp content security policy script xss security', 'ru' => 'csp политика безопасности скрипты xss защита'],
            ],
            [
                'key' => '_g_rates',
                'type' => 'group',
                'label' => 'Exchange rates',
            ],
            [
                'key' => 'rates_source',
                'type' => 'select',
                'default' => '',
                // Filled in by manifest(): the built-ins plus whatever a module
                // registered. Static here, because defaults() reads this list at
                // boot and must not depend on modules having booted yet.
                'options' => ['' => 'Do not update automatically'],
                'label' => 'Take rates from',
                'help' => '"Automatically" is the right answer for almost every shop: the central bank of the currency the shop keeps its books in — its number is the one the bank and the tax office use — and the European Central Bank behind it. The list puts the sources that quote in your currency first. The shop\'s own currency always stays at 1 and is never fetched — every other rate is expressed against it.',
            ],
            [
                'key' => 'rates_fallback',
                'type' => 'checkbox',
                'default' => true,
                'label' => 'If the source does not answer, ask the central bank of the shop\'s currency, then the European Central Bank',
                'help' => 'A bank changes its API, a mirror disappears — that is the one thing certain to happen to a rate source. With this on, the next source is asked for whatever the first one did not give, and the run\'s report names who answered. Off: a source that stops answering leaves the rates where they were until somebody notices.',
            ],
            [
                'key' => 'rates_frequency',
                'type' => 'select',
                'default' => 'daily',
                'options' => [
                    'hourly' => 'Every hour',
                    'six_hours' => 'Every six hours',
                    'daily' => 'Once a day',
                    'weekly' => 'Once a week',
                ],
                'label' => 'How often',
                'help' => 'A central bank publishes once a working day, so asking it hourly is asking the same number twenty-four times. Hourly is for an exchange.',
            ],
            [
                'key' => 'rates_markup',
                'type' => 'number',
                'default' => 0,
                'label' => 'Markup on the rate, %',
                'help' => 'An official rate is not the rate anyone will actually give you. Every fetched rate is raised by this, so a customer paying in another currency pays a little more of it — which is what covers the spread. The shop\'s own currency is never marked up.',
            ],
            [
                'key' => 'rates_max_change',
                'type' => 'number',
                'default' => 20,
                'label' => 'Refuse a rate that moves more than, %',
                'help' => 'A rate change now also reprices every product priced in that currency, so one bad number from a feed reprices the catalogue. A rate that jumps further than this in one go is refused and reported instead of applied. 0 turns the check off.',
            ],
            [
                'key' => 'rates_json_url',
                'type' => 'text',
                'default' => '',
                'label' => 'JSON endpoint: URL',
                'help' => 'Only for the "JSON endpoint of your own" source. Put {code} in the URL to ask once per currency instead of once for all of them.',
            ],
            [
                'key' => 'rates_json_base',
                'type' => 'text',
                'default' => '',
                'label' => 'JSON endpoint: its currency',
                'help' => 'The currency the feed\'s numbers are in — USD for most crypto feeds, EUR for the ECB. Empty means the shop\'s own.',
            ],
            [
                'key' => 'rates_json_path',
                'type' => 'text',
                'default' => '{code}',
                'label' => 'JSON endpoint: path to the number',
                'help' => 'A dotted path with {code} in it: rates.{code}, or {code}.usd, or data.{code}.price. {code} is the currency\'s code — or its "key in the feed", where you have given it one on the currency itself (that is how BTC becomes bitcoin).',
            ],
            [
                'key' => 'rates_json_invert',
                'type' => 'checkbox',
                'default' => false,
                'label' => 'JSON endpoint: the number is inverted',
                'help' => 'Tick when the feed says "how many X for one of its own currency" (the ECB\'s shape) instead of "what one X costs" (a bank\'s).',
            ],
            [
                'key' => 'rates_now',
                'type' => 'action',
                'url' => '/admin/currencies/refresh',
                'label' => 'Fetch the rates now',
                'help' => 'Save the settings above first. Runs in the background — the result, including anything refused, is in System → Background tasks.',
            ],
            [
                'key' => '_g_seo',
                'type' => 'group',
                'label' => 'Pages and addresses',
            ],
            [
                'key' => 'og_image',
                'type' => 'image',
                'default' => '',
                'label' => 'Default preview picture',
                'help' => 'Needed only when neither a page nor its storefront has a picture of its own — a storefront\'s is uploaded in Storefronts → Home page. A picture about 1200×630.',
            ],
            [
                'key' => 'redirect_on_rename',
                'type' => 'checkbox',
                'default' => true,
                'label' => 'Leave a redirect when an address changes',
                'help' => 'A renamed product, category, page or article keeps answering at its old address with a 301 to the new one. Switch it off for the time of a bulk edit whose in-between addresses nobody should be sent to, and back on after; redirects typed by hand or imported are not affected.',
            ],
            [
                'key' => 'redirect_forget_months',
                'type' => 'number',
                'default' => 12,
                'label' => 'Forget unused automatic redirects after, months',
                'help' => 'A redirect left by a renamed address is removed once nobody has arrived through it for this long — by then search engines know the new address. Every visit restarts the count. Redirects typed by hand or imported are kept for ever, and so is one you mark "Keep for ever". 0 keeps every redirect.',
            ],
            [
                'key' => 'url_strip_trailing_slash',
                'type' => 'checkbox',
                'default' => false,
                'label' => 'Redirect /page/ to /page',
                'help' => 'The same page answers at both addresses today, which is two pages to a search engine. A 301 says which one is the real one. Off by default: it changes what your existing links answer with.',
            ],
            [
                'key' => 'url_collapse_slashes',
                'type' => 'checkbox',
                'default' => false,
                'label' => 'Redirect //double//slashes',
                'help' => 'A mistyped or badly built link with repeated slashes answers 200 today. This sends it to the single-slash address instead.',
            ],
            [
                'key' => 'url_lowercase',
                'type' => 'checkbox',
                'default' => false,
                'label' => 'Redirect addresses with capitals to lower case',
                'help' => 'Only switch this on when every address in the shop is lower case: if any slug was imported with a capital letter in it, this redirects that page to a 404.',
            ],
            [
                'key' => '_g_agents',
                'type' => 'group',
                'label' => 'AI agents and crawlers',
            ],
            [
                'key' => 'ai_assistants',
                'type' => 'select',
                'default' => 'allow',
                'options' => [
                    'allow' => 'Allowed',
                    'deny' => 'Not allowed',
                ],
                'label' => 'Assistants that fetch a page for somebody',
                'help' => 'Somebody asks an assistant about a product and it opens that page. This is the half that sends a customer back, so it is allowed by default.',
            ],
            [
                'key' => 'ai_training',
                'type' => 'select',
                'default' => 'deny',
                'options' => [
                    'allow' => 'Allowed',
                    'deny' => 'Not allowed',
                ],
                'label' => 'Crawlers collecting pages to train on',
                'help' => 'They take the catalogue and send nobody back, and a model cannot be untrained afterwards — so the engine says no by default and leaves the decision to you.',
            ],
            [
                'key' => 'ai_assistant_agents',
                'type' => 'textarea',
                'rows' => 4,
                'default' => AgentLists::ASSISTANTS,
                'label' => 'Assistant user-agents',
                'help' => 'One name per line or comma-separated, exactly as it appears in robots.txt. A list, not code: new agents appear every month and you should not have to wait for an update to name one.',
            ],
            [
                'key' => 'ai_training_agents',
                'type' => 'textarea',
                'rows' => 4,
                'default' => AgentLists::TRAINING,
                'label' => 'Training user-agents',
                'help' => 'The same list for the other half. An agent named in neither list reads the rules for everybody.',
            ],
            [
                'key' => 'llms_summary',
                'type' => 'textarea',
                'rows' => 3,
                'default' => '',
                'label' => 'What this shop is, in a sentence',
                'help' => 'Printed at the top of /llms.txt, the map an agent reads before anything else. Empty means the shop is named there but not described.',
            ],
            [
                'key' => 'agent_rate_limit',
                'type' => 'number',
                'default' => 60,
                'label' => 'Requests per minute for the text version',
                'help' => 'Counted per address for /llms.txt and every .md page. A text rendition of the catalogue is one more way to download all of it, so it is limited the way the API is; the HTML pages keep their own traffic.',
            ],
            [
                'key' => 'robots_extra',
                'type' => 'textarea',
                'rows' => 3,
                'default' => '',
                'label' => 'Extra lines for robots.txt',
                'help' => 'Appended verbatim to the end of the generated file. The file itself no longer exists on disk — this is where a rule the shop needs and the engine never heard of goes.',
            ],
            [
                'key' => '_g_page_cache',
                'type' => 'group',
                'label' => 'Page cache',
            ],
            [
                'key' => 'page_cache',
                'type' => 'checkbox',
                'default' => false,
                'label' => 'Keep pages ready for guests',
                'help' => 'A page a guest has opened is kept and handed to the next guests as it is — several times faster. The basket, the town, the wishlist and the other parts that are a visitor\'s own are still drawn for each of them. Signed-in customers always get a freshly drawn page, and a change in the admin throws the kept pages away by itself. System → Cache and sessions shows how many there are and why a page was not kept.',
                'keywords' => ['en' => 'full page cache speed fast guests', 'ru' => 'кэш страниц скорость быстро ускорение гости'],
            ],
            [
                'key' => 'page_cache_minutes',
                'type' => 'number',
                'default' => 60,
                'label' => 'Keep a page for, minutes',
                'help' => 'At most. A change in the admin throws a page away sooner, and a page with a sale or a banner that starts or ends sooner is kept only until then.',
            ],
            [
                'key' => 'page_cache_skip',
                'type' => 'checkboxes',
                'default' => [],
                // Filled in by screenDefinitions(): each kind of page is
                // declared by the module that draws it.
                'options' => [],
                'label' => 'Always draw these pages afresh',
            ],
        ];
    }

    /**
     * key => default, for SettingsManager. Read at boot, so it does not build
     * the manifest (and does not translate anything) to get there.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::definitions() as $definition) {
            if (($definition['type'] ?? 'text') !== 'group') {
                $defaults[$definition['key']] = $definition['default'] ?? null;
            }
        }

        return $defaults;
    }

    /**
     * The definitions as the SCREEN needs them: the static ones above, with the
     * rate-source picker filled in from whoever can actually answer today.
     *
     * Separate from definitions() because that one is read at boot to register
     * defaults, and asking modules a question at that point means asking before
     * they have booted. Nothing about a default depends on the options, so the
     * two can differ safely — and this is called only when a screen is drawn.
     *
     * @return list<array<string, mixed>>
     */
    public static function screenDefinitions(): array
    {
        $sources = app(RateSources::class)->options();

        return array_map(function (array $definition) use ($sources) {
            if ($definition['key'] === 'rates_source') {
                $definition['options'] = ($definition['options'] ?? []) + $sources;
            }

            // The kinds of page a module keeps for guests (PLAN §61).
            if ($definition['key'] === \App\Core\PageCache\PageCache::SKIP) {
                $definition['options'] = page_cache()->kinds();
            }

            // Which forms can ask for a code is whoever owns them (PLAN §44).
            if ($definition['key'] === 'verify_forms') {
                $definition['options'] = array_map(fn (array $form) => $form['label'], array_filter(\App\Core\Verification\FormVerification::forms(), fn (array $form) => ! $form['always']));
            }

            return $definition;
        }, self::definitions());
    }

    /**
     * Core dressed as a module, so the settings screen needs no special case
     * beyond knowing this id.
     */
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: self::ID,
            name: __('Store'),
            version: (string) config('app.version', '1.0.0'),
            description: [],
            provider: '',
            autoload: [],
            requires: [],
            settings: self::screenDefinitions(),
            permissions: self::permissions(),
            adminMenu: [],
            zones: [],
            path: base_path(),
        );
    }
}

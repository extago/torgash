<?php

namespace Torgash\Newsletter;

use App\Core\Modules\ModuleLifecycle;
use App\Core\Privacy\Subject;
use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Torgash\Newsletter\Campaigns\CampaignLetter;
use Torgash\Newsletter\Campaigns\SendCampaigns;
use Torgash\Newsletter\Models\Campaign;
use Torgash\Newsletter\Models\Subscriber;

class NewsletterServiceProvider extends ServiceProvider implements ModuleLifecycle
{
    public function register(): void
    {
        $this->app->singleton(Subscriptions::class);
    }

    public function boot(): void
    {
        // Subscribers and mailings are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_newsletter_subscribers', 'm_newsletter_campaigns', 'm_newsletter_deliveries', 'm_newsletter_links']);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:newsletter.manage'])
            ->prefix('admin/newsletter')
            ->name('admin.newsletter.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        hook_listen('core.mail.events', fn (array $events) => $events + Subscriptions::letters() + CampaignLetter::event());

        $this->campaigns();

        // The guard's screen lists it (CAPTCHA on this form or not).
        hook_listen('core.forms', fn (array $forms) => $forms + [Http\NewsletterController::FORM => 'Newsletter subscription']);

        // The letters' pages and the account page are nobody's to index.
        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'newsletter.*', 'account.newsletter*']);

        // Its part of the demo shop (PLAN §55): subscribers in every state.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'newsletter' => ['label' => 'Newsletter subscribers', 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\NewsletterDemo::class)->install($pack)],
        ]);

        $this->personalData();
        $this->blockType();
        $this->placesOnTheShop();
    }

    /**
     * Campaigns (PLAN §57.4): the letter's own addresses — outside `web`, a
     * mail client has no session and no CSRF token — its pictures, and the
     * scheduler's minute that sends what is due.
     */
    protected function campaigns(): void
    {
        Route::middleware('throttle:120,1')->group(function () {
            Route::get('newsletter/o/{token}.gif', [Http\TrackingController::class, 'open'])->where('token', '[A-Za-z0-9]{40}')->name('newsletter.open');
            Route::get('newsletter/c/{token}/{link}', [Http\TrackingController::class, 'click'])->where(['token' => '[A-Za-z0-9]{40}', 'link' => '[0-9]+'])->name('newsletter.click');
            Route::post('newsletter/u/{token}', [Http\TrackingController::class, 'oneClick'])->where('token', '[A-Za-z0-9]{40}')->name('newsletter.one-click');
        });

        hook_listen('media.owners', fn (array $context) => $context['owners']->register('newsletter_campaign', Campaign::class, 'newsletter.manage', content: true));

        // Asked every minute, started only when there is something to send.
        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(function () {
                if (Campaign::where('status', Campaign::SENDING)
                    ->orWhere(fn ($q) => $q->where('status', Campaign::SCHEDULED)->where('scheduled_at', '<=', now()))
                    ->exists()) {
                    SendCampaigns::startIfIdle();
                }
            })->everyMinute()->name('newsletter:campaigns');
        });
    }

    /** Core's registry (PLAN §49): an address on the list, with the evidence of its consent. */
    protected function personalData(): void
    {
        hook_listen('core.personal_data', fn (array $context) => $context['registry']->register('newsletter', [
            'label' => 'Newsletter subscription',
            'export' => fn (Subject $s) => $this->rows($s)->map(fn (Subscriber $r) => [
                'email' => $r->email, 'status' => $r->status, 'source' => $r->source,
                'subscribed_at' => $r->confirmed_at?->toIso8601String(), 'unsubscribed_at' => $r->unsubscribed_at?->toIso8601String(),
                'consent_ip' => $r->consent_ip, 'consent_text' => $r->consent_text,
            ])->all(),
            // Its letters' rows go too; the campaigns keep their counts.
            'erase' => function (Subject $s) {
                $rows = $this->rows($s);
                \Torgash\Newsletter\Models\Delivery::whereIn('email', $rows->pluck('email'))->delete();

                return $rows->each->delete()->count();
            },
        ]));
    }

    protected function rows(Subject $s)
    {
        $emails = array_map(fn ($e) => Str::lower((string) $e), [...$s->emails, ...($s->user?->email ? [$s->user->email] : [])]);

        return Subscriber::query()
            ->where(fn ($q) => $q->whereIn('email', $emails ?: ['']))
            ->when($s->user, fn ($q) => $q->orWhere('user_id', $s->user->id))
            ->get();
    }

    /** "Subscribe" — a block the shop places and may lay out in its own HTML. */
    protected function blockType(): void
    {
        if (! class_exists(\Torgash\Blocks\BlockTypes::class)) {
            return;
        }

        \Torgash\Blocks\BlockTypes::register('newsletter', [
            'label' => 'Newsletter subscription',
            'description' => 'An email field and a button; laid out by the theme, or by your own HTML with {form} in it.',
            'icon' => 'mail-plus',
            'view' => 'torgash.newsletter::blocks.newsletter',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading', 'help' => 'Empty for "News and offers".'],
                ['key' => 'text', 'type' => 'textarea', 'translatable' => true, 'label' => 'Text'],
                ['key' => 'button', 'type' => 'text', 'translatable' => true, 'label' => 'Button', 'help' => 'Empty for "Subscribe".'],
                ['key' => 'placeholder', 'type' => 'text', 'translatable' => true, 'label' => 'Hint in the field', 'help' => 'Empty for "Your email".'],
                [
                    'key' => 'template', 'type' => 'html', 'translatable' => true, 'permission' => 'blocks.html',
                    'label' => 'Your own HTML',
                    'help' => 'Empty — the theme lays it out. Your own: {form} (the field and the button — required), {heading}, {text}, {button}, {privacy_link}, {subscribers} and the shop\'s placeholders.',
                ],
            ],
        ]);
    }

    /**
     * The basket's offer and the checkout tick — both settings, both off-able.
     * The tick is read when the order is placed, for the order's email.
     */
    protected function placesOnTheShop(): void
    {
        hook_listen('cart.page.bottom', fn (string $html) => setting('torgash.newsletter', 'offer_in_cart', true)
            ? $html.view('torgash.newsletter::storefront.cart-offer')->render()
            : $html);

        hook_listen('checkout.form.consents', fn (string $html) => setting('torgash.newsletter', 'offer_at_checkout', true)
            ? $html.view('torgash.newsletter::storefront.checkout-tick')->render()
            : $html);

        hook_listen('checkout.order_placed', function (array $context) {
            $order = $context['order'] ?? null;

            if ($order) {
                app(Subscriptions::class)->fromOrder((string) $order->email, (string) ($order->order_number ?? $order->id), request()->boolean('newsletter_subscribe'));
            }
        });

        hook_listen('core.account.nav', fn (array $items) => [...$items, [
            'label' => __('Newsletter'),
            'url' => route('account.newsletter'),
            'group' => 'account',
            'icon' => 'mail-plus',
            'active' => request()->routeIs('*account.newsletter*'),
        ]]);
    }

    public function install(): void {}

    /** Every campaign through its model, so the pictures inside its letter go with it. */
    public function uninstall(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('m_newsletter_campaigns')) {
            Campaign::query()->get()->each->delete();
        }
    }
}

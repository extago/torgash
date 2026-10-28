<?php

namespace Torgash\Enquiries;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Torgash\Blocks\BlockTypes;
use Torgash\Catalog\Models\Product;

class EnquiriesServiceProvider extends ServiceProvider
{
    /**
     * The id of the one callback dialog on the page. A stable name, because
     * anything — a header button, an admin's own HTML block, the
     * {callback_link} token — opens it by that name and nothing else needs to
     * know where the markup came from.
     */
    public const CALLBACK_MODAL = 'enquiry-callback';

    public function register(): void
    {
        // The shop's own forms (PLAN §48), read once per request — reset with
        // it, so a worker never serves a form the shop has since edited.
        $this->app->singleton(Forms::class);
        PerRequest::forget($this->app, Forms::class);
    }

    public function boot(): void
    {
        // A customer's enquiry and its conversation are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_enquiries_enquiries', 'm_enquiries_messages']);

        // What it keeps about a person, for core's personal-data registry (PLAN §49).
        hook_listen('core.personal_data', fn (array $context) => EnquiryPersonalData::register($context['registry']));

        // Its card on core's admin dashboard.
        app(Admin\DashboardWidgets::class)->register();

        // The admin's bell: the queue — open enquiries the shop owes an answer
        // to (nothing said yet, or the customer spoke last), the same question
        // the API's `awaiting_reply` answers.
        hook_listen('core.admin.notifications', function (array $rows, array $context) {
            if (! ($context['user'] ?? null)?->can('enquiries.manage')) {
                return $rows;
            }

            return [...$rows, [
                'key' => 'enquiries',
                'label' => __('Enquiries awaiting a reply'),
                'count' => \Torgash\Enquiries\Models\Enquiry::query()->awaitingReply()->count(),
                'url' => route('admin.enquiries.index', ['view' => 'awaiting']),
                'icon' => 'inbox',
            ]];
        });

        Kinds::registerBuiltIn();

        // The customer card and list: this person's threads.
        app(Admin\CustomerEnquiries::class)->register();

        // Whoever else wants an inbox. Answered lazily at boot for the same
        // reason api.resources is: provider order is not ours to depend on.
        hook_action('enquiries.kinds');

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // Outside StorefrontRoutes on purpose: this is an action, not a page,
        // so it needs no locale shape — and a signed URL is signed as one exact
        // address, which a per-locale copy would only multiply. `web` because
        // the person posting it is in a browser with a CSRF token, unlike a
        // gateway callback.
        Route::middleware(['web', 'signed'])
            ->post('/enquiry/{enquiry}/reply', Http\SignedReplyController::class)
            ->name('enquiries.signed_reply');

        // Attachments are not on the public disk, so this is how one is read.
        // Outside StorefrontRoutes for the same reason as the reply above, and
        // NOT behind `signed` middleware: the signature is one of three ways in
        // (staff, the owning account, a signature), and the controller weighs
        // them together — `signed` here would refuse a logged-in manager.
        Route::middleware('web')
            ->get('/enquiry-file/{media}', Http\AttachmentController::class)
            ->name('enquiries.file');

        // The shop's own forms (PLAN §48). Registered BEFORE the inbox, whose
        // `/{enquiry}` would otherwise read "forms" as an enquiry id, and under
        // a permission of their own: building a form is not answering one.
        Route::middleware(['web', 'auth', 'can:enquiries.forms'])
            ->prefix('admin/enquiries/forms')
            ->name('admin.enquiries.forms.')
            ->group(function () {
                Route::get('/', [Http\Admin\FormsController::class, 'index'])->name('index');
                Route::get('/create', [Http\Admin\FormsController::class, 'create'])->name('create');
                Route::post('/', [Http\Admin\FormsController::class, 'store'])->name('store');
                Route::get('/{form}/edit', [Http\Admin\FormsController::class, 'edit'])->name('edit');
                Route::put('/{form}', [Http\Admin\FormsController::class, 'update'])->name('update');
                Route::delete('/{form}', [Http\Admin\FormsController::class, 'destroy'])->name('destroy');
            });

        Route::middleware(['web', 'auth', 'can:enquiries.manage'])
            ->prefix('admin/enquiries')
            ->name('admin.enquiries.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // The public form this module owns, named for the CAPTCHA screen and
        // for anything else that needs to enumerate what a shop exposes.
        // Its letters in core's registry (PLAN §41). Fully qualified: a
        // `Mail` alias here may be the Laravel facade.
        hook_listen('core.mail.events', fn (array $events) => $events + \Torgash\Enquiries\Mail\EnquiryLetters::events());

        hook_listen('core.forms', fn (array $forms) => $forms + [
            Submit::FORM => 'Enquiries and callbacks',
        ]);

        // Each kind can ask its contact to be confirmed by a code (PLAN §44)
        // — one entry per kind, because a callback carries a phone and "tell
        // me when it is back" may carry only an email. A phone first where a
        // kind has both: that is what gets abused.
        hook_listen('core.verified_forms', function (array $forms) {
            foreach (Kinds::all() as $key => $kind) {
                $contacts = array_values(array_intersect(['phone', 'email'], array_column(Kinds::fields($key), 'key')));

                if ($contacts !== []) {
                    $forms[Submit::verifiedForm($key)] = ['label' => Kinds::label($key), 'kinds' => $contacts];
                }
            }

            return $forms;
        });

        $this->describeProducts();
        $this->productButtons();
        $this->callbackDialog();
        $this->registerBlockType();
        $this->takeOverCallbackToken();
        $this->formToken();
        $this->accountMenu();
        $this->schedule();
        $this->registerApiResource();
        $this->registerEximHooks();
    }

    /**
     * A thread anybody may be shown, with the right to write into it carried by
     * a signature rather than a session.
     *
     * Handed out as a rendered partial rather than a route to link to, because
     * the caller — the order page, today — decides what it is showing and this
     * module must not learn what an order is to help.
     */
    public static function thread(Models\Enquiry $enquiry, bool $mayReply = true): string
    {
        return view('torgash.enquiries::partials.thread', [
            'enquiry' => $enquiry->load('messages'),
            'replyAction' => $mayReply
                ? URL::signedRoute('enquiries.signed_reply', $enquiry)
                : null,
        ])->render();
    }

    /**
     * Housekeeping: close a thread the shop answered and nobody came back to.
     *
     * Registered unconditionally and no-ops on the setting, unlike Nova
     * Poshta's schedule (which is gated at boot because its job would error
     * without an API key): here the setting is the whole rule, and gating at
     * boot would mean switching it on took a fresh boot to start working.
     */
    protected function schedule(): void
    {
        hook_listen('core.schedule', function (array $context) {
            $context['schedule']
                ->call(fn () => AutoClose::run())
                ->hourly()
                ->name('enquiries:auto-close')
                ->withoutOverlapping();

            $context['schedule']
                ->call(fn () => Overdue::sweep())
                ->everyFiveMinutes()
                ->name('enquiries:overdue')
                ->withoutOverlapping();
        });

        // Rules about an enquiry. Answered only when torgash.automations asks,
        // so these classes — which implement that module's interfaces — are
        // never loaded in a shop without it.
        hook_listen('automations.subjects', fn (array $subjects) => $subjects + [
            'enquiry' => Automations\EnquirySubject::class,
        ]);
        hook_listen('automations.actions', fn (array $actions) => $actions + [
            'set_enquiry_status' => Automations\SetEnquiryStatusAction::class,
            'set_enquiry_manager' => Automations\SetEnquiryManagerAction::class,
        ]);
    }

    /**
     * "Enquiries" in the account menu.
     *
     * An ITEM answered into core's list, not markup appended to a zone: the
     * menu's classes and its active state stay core's, so this row cannot drift
     * away from Orders and Addresses when the theme changes. Core still does
     * not know this module exists — uninstall it and the item goes with the
     * routes it points at.
     *
     * The route name is matched with a leading star because the locale-prefixed
     * copy of it is called `ru.account.enquiries.index` — see StorefrontRoutes.
     *
     * The badge counts answers the customer has not opened. One indexed EXISTS
     * per render of the menu, and only for somebody logged in — the header
     * dropdown draws it on every page, so a guest must not pay for it.
     */
    protected function accountMenu(): void
    {
        hook_listen('core.account.nav', fn (array $items) => [...$items, [
            'label' => __('Enquiries'),
            'group' => 'shopping',
            'url' => route('account.enquiries.index'),
            'icon' => 'inbox',
            'active' => request()->routeIs('*account.enquiries.*'),
            'badge' => auth()->check()
                ? Models\Enquiry::where('user_id', auth()->id())->unreadByCustomer()->count()
                : 0,
        ]]);
    }

    /**
     * Neither torgash.api nor torgash.exim is in this module's manifest
     * requires — same soft-dependency shape as checkout's own
     * registerEximHooks(): a harmless no-op if the other module isn't
     * installed, because the closure only runs when that module actually
     * asks for it.
     */
    protected function registerApiResource(): void
    {
        hook_listen('api.resources', fn () => (new Api\EnquiryResources)->register());
    }

    /**
     * Export only, on purpose: an enquiry has no natural key an outside system
     * would already know it by (unlike a product's sku or an order's number),
     * so there is nothing for an import to match against — §23.4's "when it's
     * needed to export into a CRM" stops at reading the inbox out, not
     * feeding it back in.
     */
    protected function registerEximHooks(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + ['enquiry' => 'Enquiry']);

        hook_listen('exim.query.enquiry', fn () => Models\Enquiry::query()->with(['manager', 'storefront']));

        hook_listen('exim.schema.enquiry', fn (array $fields) => [...$fields,
            ['key' => 'id', 'label' => 'ID', 'get' => fn (Models\Enquiry $e) => $e->id, 'set' => null],
            ['key' => 'kind', 'label' => 'Kind', 'get' => fn (Models\Enquiry $e) => $e->kindLabel(), 'set' => null],
            ['key' => 'status', 'label' => 'Status', 'get' => fn (Models\Enquiry $e) => $e->status, 'set' => null],
            ['key' => 'name', 'label' => 'Name', 'get' => fn (Models\Enquiry $e) => $e->name, 'set' => null],
            ['key' => 'phone', 'label' => 'Phone', 'get' => fn (Models\Enquiry $e) => $e->phone, 'set' => null],
            ['key' => 'email', 'label' => 'Email', 'get' => fn (Models\Enquiry $e) => $e->email, 'set' => null],
            ['key' => 'subject', 'label' => 'Subject', 'get' => fn (Models\Enquiry $e) => ($e->subject() ?? [])['label'] ?? '', 'set' => null],
            [
                'key' => 'answers', 'label' => 'Answers', 'set' => null,
                'get' => fn (Models\Enquiry $e) => collect($e->answers())
                    ->map(fn ($a) => $a['label'].': '.$a['value'])
                    ->implode('; '),
            ],
            ['key' => 'customer_ref', 'label' => 'Customer ref', 'get' => fn (Models\Enquiry $e) => $e->customer_ref, 'set' => null],
            ['key' => 'manager', 'label' => 'Manager', 'get' => fn (Models\Enquiry $e) => $e->manager?->name, 'set' => null],
            ['key' => 'notes', 'label' => 'Notes', 'get' => fn (Models\Enquiry $e) => $e->notes, 'set' => null],
            ['key' => 'answered_at', 'label' => 'Answered at', 'get' => fn (Models\Enquiry $e) => $e->answered_at?->toDateTimeString(), 'set' => null],
            ['key' => 'created_at', 'label' => 'Created at', 'get' => fn (Models\Enquiry $e) => $e->created_at?->toDateTimeString(), 'set' => null],
        ]);
    }

    /**
     * One callback dialog per page, at the end of it (`core.footer`, where
     * every other page-wide dialog is), rendered whether or not anything on
     * the page opens it. Not in a footer ZONE: a zone is drawn as a band of
     * the footer only when something is in it, and an invisible dialog would
     * make an empty band.
     *
     * Once rather than beside each button: a <dialog> is opened by id from
     * anywhere, and rendering one per trigger would put three copies of the
     * same form (and three csrf tokens) on a page that shows the button in the
     * header, under the buy box and in the footer.
     */
    protected function callbackDialog(): void
    {
        hook_listen('core.footer', function (string $value) {
            if (! setting('torgash.enquiries', 'callback', true)) {
                return $value;
            }

            return $value.view('torgash.enquiries::partials.callback-modal')->render();
        });
    }

    /**
     * What a product subject is called, and where it lives.
     *
     * Answered here rather than by catalog, because catalog must not learn
     * about this module to keep working — the dependency points this way, and
     * the filter is what lets a module that registers its own kind describe
     * its own subject without either of them meeting.
     */
    protected function describeProducts(): void
    {
        hook_listen('enquiries.subject.describe', function (?array $value, array $context) {
            if ($value || ($context['type'] ?? null) !== 'product') {
                return $value;
            }

            $product = Product::find($context['id']);

            return $product ? [
                'label' => $product->t('name'),
                'url' => route('catalog.product', $product->slug),
            ] : null;
        });
    }

    /**
     * The two buttons on a product page, under the buy box — the same hook
     * the info-block module hangs off, appending rather than replacing, so
     * neither has to know the other exists.
     *
     * "Tell me when it is back" is offered only when it is true: asking to be
     * told about a product that is on the shelf reads as a broken shop.
     */
    protected function productButtons(): void
    {
        hook_listen('cart.buy_box.links', function (string $value, array $context) {
            $product = $context['product'];

            return $value.view('torgash.enquiries::partials.product-buttons', [
                'product' => $product,
                'askEnabled' => (bool) setting('torgash.enquiries', 'product_question', true),
                // Not orderable, AND its stock is a number that could plausibly
                // move again (PLAN §31.3) — a preorder is already buyable, and a
                // discontinued product is not coming back because a number
                // changed, so "tell me when it's back" would be a promise this
                // shop cannot keep.
                'notifyEnabled' => (bool) setting('torgash.enquiries', 'back_in_stock', true)
                    && ! $product->isOrderable()
                    && app(\Torgash\Catalog\Stock\AvailabilityStatuses::class)->tracksStock((string) $product->availability_status),
            ])->render();
        });
    }

    /**
     * A form anywhere an admin can put a block.
     *
     * A block TYPE rather than "write the markup in an HTML block", which is
     * the opposite of the decision made for popups — and the difference is
     * that this one has a schema. Which kind, what the heading says, where to
     * send the person afterwards: three answers a form needs and an admin
     * should pick from a list, not retype as attributes that silently do
     * nothing when misspelled.
     */
    protected function registerBlockType(): void
    {
        if (! class_exists(BlockTypes::class)) {
            return;
        }

        BlockTypes::register('enquiry', [
            'description' => 'A form that sends a question or a callback request to the enquiries inbox.',
            'fits' => ['wide', 'narrow'],
            'label' => 'Enquiry form',
            'icon' => 'message-square-plus',
            'view' => 'torgash.enquiries::blocks.enquiry',
            'schema' => [
                [
                    'key' => 'kind',
                    'type' => 'select',
                    'label' => 'What is being asked',
                    'default' => 'message',
                    // Built from the registry, so a kind another module adds
                    // — or a form the shop built (PLAN §48) — is on this list
                    // without touching this file. A closure: the shop's forms
                    // are rows, and boot is no place to read them.
                    'options' => fn () => array_map(
                        fn ($key) => ['value' => $key, 'label' => Kinds::label($key)],
                        array_keys(Kinds::all()),
                    ),
                ],
                [
                    'key' => 'heading',
                    'type' => 'text',
                    'translatable' => true,
                    'label' => 'Heading',
                ],
                [
                    'key' => 'note',
                    'type' => 'textarea',
                    'translatable' => true,
                    'label' => 'Text above the form',
                ],
            ],
        ]);
    }

    /**
     * {callback_link} in admin-written content now opens the form.
     *
     * Core registers that token with a comment saying dialling is the honest
     * fallback until a callback form exists. It exists now — but core still
     * must not know this module's name, so core asks through a filter and this
     * answers with the modal's anchor. Uninstall the module and the token goes
     * back to tel:, with no content to fix.
     */
    /**
     * `{form:code}` — a form the shop built, wherever a text is written
     * (PLAN §48): a page, a blog article, an HTML block. A placeholder rather
     * than one more block type because the point is to put the form BETWEEN
     * two paragraphs of the text it belongs to — "Delivery" ends with "ask us
     * about yours", and the form is right there. On a line of its own it
     * replaces that paragraph (`block`), since a form inside a <p> is markup a
     * browser splits apart. An unknown, or switched-off, code leaves nothing.
     */
    protected function formToken(): void
    {
        app(\App\Core\Content\Placeholders::class)->register('form', [
            'label' => 'A form of yours',
            'group' => 'forms',
            'raw' => true,
            'block' => true,
            'argument' => ['label' => 'code', 'example' => 'feedback'],
            'resolve' => function (array $context = [], ?string $code = null) {
                $form = $code ? app(Forms::class)->byCode(trim($code)) : null;

                return $form && $form->enabled
                    ? view('torgash.enquiries::form-embed', ['embedForm' => $form])->render()
                    : '';
            },
        ]);
    }

    protected function takeOverCallbackToken(): void
    {
        // The other half of "tell me when it is back": the catalogue says when
        // a product can be bought again (whatever moved it — a delivery, an
        // import, a status edit) and the people who asked are told, in their
        // own language, in the thread they can answer. Queued, because one
        // import restocks hundreds of products at once. A soft dependency in
        // both directions: with the catalogue absent this listener is never
        // called, and the catalogue has never heard of this module.
        hook_listen('catalog.product.restocked', function (array $context) {
            if ($id = (int) ($context['product']->id ?? 0)) {
                Jobs\NotifyRestocked::dispatch($id);
            }
        });

        hook_listen('core.contacts.callback', function (string $value) {
            return setting('torgash.enquiries', 'callback', true)
                ? '#'.self::CALLBACK_MODAL
                : $value;
        });
    }
}

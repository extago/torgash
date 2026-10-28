<?php

namespace Torgash\Rma;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Rma\Models\ReturnRequest;

/**
 * Returns and exchanges, as a second layer on tickets.
 *
 * Everything this module does not do is the point: no inbox, no statuses, no
 * manager, no notifications, no conversation — those are the enquiry's. No
 * refunds of its own, no stock arithmetic of its own, no order status column
 * written by hand — those go through checkout's own code, which is what stops
 * a return drifting away from what the shop does everywhere else.
 */
class RmaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request, and therefore a singleton rather than a static: under a
        // persistent worker a static would answer the next visitor with this
        // one's rows.
        $this->app->singleton(Returns::class);
    }

    public function boot(): void
    {
        // Returns are on no guest's page (PLAN §61).
        page_cache()->unseen('m_rma_returns');

        Kind::register();

        // A return names an order's lines by their keys and counts what is
        // left of THAT order to return; pouring the order into another (PLAN
        // §40.10/4) renames its lines and leaves the return pointing at a
        // shell. The order poured INTO keeps its keys, so its return is not
        // in the way.
        hook_listen('checkout.order.merge_refusals', function (array $refusals, array $context) {
            if (\Illuminate\Support\Facades\DB::table('m_rma_returns')->where('order_id', $context['from']->id)->exists()) {
                $refusals[] = __('Order :number has a return.', ['number' => $context['from']->order_number]);
            }

            return $refusals;
        });

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // What must be true about returns, for whoever is watching. Nobody
        // fires this filter when `torgash.guard` is absent.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\RmaInvariants)->register());

        // Under the enquiries prefix, not one of our own, and deliberately:
        // these are buttons on a ticket, and the shop owner navigating to
        // /admin/enquiries/... is where they already are. `rma.manage` because
        // approving a refund is not the same permission as answering a
        // question — a shop can let everybody do the second.
        Route::middleware(['web', 'auth', 'can:rma.manage'])
            ->prefix('admin/enquiries/returns')
            ->name('admin.rma.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->offerOnTheOrderPage();
        $this->offerOnTheTicket();
        $this->statusOptions();

        // torgash.api is not in this module's requires — the same soft
        // dependency every other module has: the closure only runs if that
        // module is there to ask.
        hook_listen('api.resources', fn () => (new Api\ReturnResources)->register());
    }

    /**
     * The returns half of the order page — a button beside "Ask about this
     * order", and, on a ticket that is a return, what is coming back.
     *
     * Checkout draws the panel: it owns the order, so it owns "the
     * conversations attached to one", and a shop that takes no returns still
     * has customers asking where their parcel is. This module adds two strings
     * to it and nothing else, which is why uninstalling returns leaves the
     * page working rather than empty.
     */
    protected function offerOnTheOrderPage(): void
    {
        hook_listen('checkout.order.enquiry_actions', function (string $value, array $context) {
            /** @var Order $order */
            $order = $context['order'];

            if (Eligibility::refusal($order)) {
                return $value;
            }

            return $value.view('torgash.rma::order-request', [
                'order' => $order,
                'remaining' => Eligibility::remaining($order),
            ])->render();
        });

        // What a return says that a plain question does not: where the goods
        // are, and what was asked for.
        hook_listen('checkout.order.enquiry_extra', function (string $value, array $context) {
            $return = app(Returns::class)->forEnquiry($context['enquiry']->id);

            return $return
                ? $value.view('torgash.rma::order-return', ['return' => $return])->render()
                : $value;
        });
    }

    /**
     * The handling card on the ticket, and the state badge in the inbox list.
     *
     * Both through hooks enquiries provides, so that module still does not know
     * what a return is — and both resolving the memo INSIDE the closure, never
     * capturing it at boot: a listener outlives the request, and a captured
     * per-request singleton is a static wearing a different hat.
     */
    protected function offerOnTheTicket(): void
    {
        hook_listen('enquiries.admin.extra', function (string $value, array $context) {
            /** @var Enquiry $enquiry */
            $enquiry = $context['enquiry'];
            $return = app(Returns::class)->forEnquiry($enquiry->id);

            // No return on this ticket yet: the shop may still decide there
            // should be one. That is the owner's case — a question that turns
            // out to be a fault — and it is why the card is offered on EVERY
            // kind of ticket rather than only on the ones a customer filed as a
            // return.
            if (! $return) {
                return auth()->user()?->can('rma.manage')
                    ? $value.$this->drawUpForm($enquiry)
                    : $value;
            }

            return $value.view('torgash.rma::admin.panel', [
                'return' => $return,
                'order' => $return->order,
                // Empty in a shop with no warehouses module, which is what
                // makes the picker disappear rather than the card break.
                'warehouses' => hook_filter('catalog.stock.warehouses', []),
                'mayHandle' => auth()->user()?->can('rma.manage') ?? false,
            ])->render();
        });

        // Announced by the list before it draws a row, so the badge below costs
        // one query for the page instead of one per line.
        hook_listen('enquiries.listed', function (array $context) {
            app(Returns::class)->primeFrom($context['enquiries'] ?? []);
        });

        hook_listen('enquiries.admin.badge', function (string $value, array $context) {
            $return = app(Returns::class)->forEnquiry($context['enquiry']->id);

            return $return
                ? $value.'<span class="badge badge-sm '.State::badge($return->state).' ml-1">'
                    .e(State::label($return->state)).'</span>'
                : $value;
        });
    }

    /**
     * The form that turns a ticket into a return.
     *
     * Which order it is about comes from the ticket's own subject when it has
     * one — a question asked from the order page does — and otherwise from a
     * number the manager types, carried in the query so the second step can
     * show that order's lines without any state or any JavaScript.
     */
    protected function drawUpForm(Enquiry $enquiry): string
    {
        $number = trim((string) request()->query('rma_order', ''));

        $order = $number !== ''
            ? Order::where('order_number', $number)->first()
            : ($enquiry->subject_type === 'order' ? Order::find($enquiry->subject_id) : null);

        return view('torgash.rma::admin.create', [
            'enquiry' => $enquiry,
            'order' => $order,
            'orderNumber' => $number !== '' ? $number : ($order->order_number ?? ''),
            'remaining' => $order ? Eligibility::remaining($order) : [],
        ])->render();
    }

    /**
     * The shop's own order statuses, offered as the tick-boxes of this module's
     * first setting.
     *
     * They cannot be in manifest.json: no two shops have the same statuses, and
     * a JSON file written months ago has never heard of this one's. Core asks
     * through `core.settings.fields` on the way to the screen AND on the way
     * back, so what is ticked is what is saved.
     */
    protected function statusOptions(): void
    {
        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== 'torgash.rma') {
                return $fields;
            }

            $all = OrderStatus::orderBy('position')->get();

            $statuses = $all->mapWithKeys(fn (OrderStatus $s) => [$s->code => $s->t('name')])->all();

            // The status a completed return moves the ORDER to is offered from
            // a shorter list, and the omission is not tidiness: a status whose
            // inventory_action is "increase" puts the WHOLE order back into
            // stock when the order enters it, and this module has just put the
            // returned lines back itself. Offering it would give a shop one
            // click that counts the same goods twice — so it is not offered,
            // rather than warned about in help text nobody reads.
            $settling = ['' => __('Leave the order status alone')] + $all
                ->reject(fn (OrderStatus $s) => $s->inventory_action === 'increase')
                ->mapWithKeys(fn (OrderStatus $s) => [$s->code => $s->t('name')])
                ->all();

            return array_map(function (array $field) use ($statuses, $settling) {
                if ($field['key'] === 'statuses') {
                    $field['options'] = $statuses;
                }

                if ($field['key'] === 'completed_status') {
                    $field['options'] = $settling;
                }

                return $field;
            }, $fields);
        });
    }

    /**
     * The return filed on this ticket, if it is one.
     *
     * Kept as the module's public answer to that question — through the memo,
     * so a caller asking about a page of enquiries does not pay per row.
     */
    public static function forEnquiry(Enquiry $enquiry): ?ReturnRequest
    {
        return app(Returns::class)->forEnquiry($enquiry->id);
    }
}

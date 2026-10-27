<?php

namespace Torgash\Checkout\Enquiries;

use Illuminate\Support\Facades\Route;
use Torgash\Checkout\Models\Order;
use Torgash\Enquiries\EnquiriesServiceProvider;
use Torgash\Enquiries\Kinds;
use Torgash\Enquiries\Models\Enquiry;

/**
 * The conversation attached to an order: asking about one, and reading what was
 * said.
 *
 * This lived in torgash.rma until the owner asked the obvious question — what
 * if the person simply has a QUESTION about their order? A shop selling
 * downloads takes no returns and still has customers asking where their licence
 * key is, and putting "ask about your order" inside the returns module means
 * uninstalling returns takes it away, along with the inbox's ability to say
 * what "order TG-000025" is.
 *
 * So the half that is about ORDERS is here, where orders are owned, and the
 * returns module adds its own half through the two hooks below. Which is the
 * project's usual rule stated once more: whoever owns the row describes it.
 *
 * Soft dependency in both directions. Every registration is either answered
 * lazily through a hook enquiries asks (`enquiries.kinds`) or guarded by
 * `available()` — the same trick PlaceOrder::shippingIsActive() uses, asking
 * the route table rather than the module list, because what actually matters is
 * whether anything is there to answer.
 */
class OrderEnquiries
{
    /** The kind a question asked from the order page is filed under. */
    public const KIND = 'order_question';

    /** Is there an enquiries module here at all? */
    public static function available(): bool
    {
        return Route::has('enquiries.signed_reply');
    }

    public function register(): void
    {
        // Answered lazily: this only runs if enquiries is installed and asking.
        hook_listen('enquiries.kinds', function () {
            Kinds::register(self::KIND, [
                'label' => 'Question about an order',
                'icon' => 'package-search',
                // Set from the order the form was opened on, never read from the
                // request — the same rule every kind with a subject follows.
                'subject' => 'order',
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'label' => 'Your name'],
                    ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
                    ['key' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
                    ['key' => 'question', 'type' => 'textarea', 'required' => true,
                        'label' => 'Your question'],
                ],
            ]);
        });

        $this->describeOrders();
        $this->drawOnTheOrderPage();
    }

    /**
     * What an order subject is called in the inbox, and where it links.
     *
     * Moved here from the returns module for the reason in the class docblock:
     * an inbox that cannot say what "order TG-000025" is because returns were
     * uninstalled is an inbox printing a bare number at a manager.
     */
    protected function describeOrders(): void
    {
        hook_listen('enquiries.subject.describe', function (?array $value, array $context) {
            if ($value || ($context['type'] ?? null) !== 'order') {
                return $value;
            }

            $order = Order::find($context['id']);

            return $order ? [
                'label' => __('Order :number', ['number' => $order->order_number]),
                'url' => route('admin.orders.show', $order->order_number),
            ] : null;
        });

        // The inbox's "this customer" card: their orders, by the reference an
        // enquiry and an order both carry (derived from the same identity, so
        // a guest who asked and a guest who bought are one person) or by
        // their account. Never by email alone — see §29.3/1.
        hook_listen('enquiries.admin.person', function (string $value, array $context) {
            $enquiry = $context['enquiry'];
            if (! $enquiry->customer_ref && ! $enquiry->user_id) {
                return $value;
            }

            $query = fn () => Order::query()->where(function ($w) use ($enquiry) {
                $w->whereRaw('false');
                if ($enquiry->customer_ref) {
                    $w->orWhere('customer_ref', $enquiry->customer_ref);
                }
                if ($enquiry->user_id) {
                    $w->orWhere('user_id', $enquiry->user_id);
                }
            });

            $orders = $query()->latest('id')->limit(5)->get();

            return $orders->isEmpty() ? $value : $value.view('torgash.checkout::admin.orders._person', [
                'personOrders' => $orders,
                'personOrderCount' => $query()->count(),
                'personStatuses' => \Torgash\Checkout\Models\OrderStatus::all()->keyBy('code'),
            ])->render();
        });
    }

    /**
     * The panel on the order page: every ticket filed against THIS order, each
     * with its conversation, and a form to start another.
     *
     * Only this order's. A guest is identified by the key they hold, so "their
     * other enquiries" is a question nothing here can answer without matching
     * on an email address — which §29.3/1 refuses.
     *
     * Two hooks for whoever has more to say about an order's tickets:
     * `checkout.order.enquiry_actions` adds a button beside "Ask a question"
     * (the returns module's "Return or exchange"), and
     * `checkout.order.enquiry_extra` adds to one ticket's own block (a return's
     * state and its lines). Both are strings, both seeded empty, so a shop
     * without that module simply gets the plain conversation.
     */
    protected function drawOnTheOrderPage(): void
    {
        hook_listen('checkout.confirmation.extra', function (string $value, array $context) {
            if (! self::available()) {
                return $value;
            }

            /** @var Order $order */
            $order = $context['order'];

            $enquiries = Enquiry::where('subject_type', 'order')
                ->where('subject_id', $order->id)
                ->with('messages')
                ->orderBy('id')
                ->get();

            return $value.view('torgash.checkout::order-enquiries', [
                'order' => $order,
                'enquiries' => $enquiries,
                'threads' => $enquiries->mapWithKeys(
                    fn ($enquiry) => [$enquiry->id => EnquiriesServiceProvider::thread($enquiry)],
                )->all(),
                'extras' => $enquiries->mapWithKeys(
                    fn ($enquiry) => [$enquiry->id => hook_filter('checkout.order.enquiry_extra', '', [
                        'enquiry' => $enquiry, 'order' => $order,
                    ])],
                )->all(),
                'actions' => hook_filter('checkout.order.enquiry_actions', '', ['order' => $order]),
            ])->render();
        });
    }
}

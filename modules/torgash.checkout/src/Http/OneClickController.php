<?php

namespace Torgash\Checkout\Http;

use App\Core\Support\FormGuard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\CouponManager;
use Torgash\Checkout\Orders\OneClick;
use Torgash\Checkout\Orders\PlaceOrder;
use Torgash\Checkout\Shipping\Location;

/**
 * One product, one phone number, one order.
 *
 * The same guard as any other public form — and here it matters more than on an
 * enquiry: this endpoint creates ORDERS, and an unguarded one would fill the
 * shop's list with rubbish that has already moved stock.
 */
class OneClickController extends Controller
{
    /** Counted apart from the enquiry form: asking a question and then buying is not one act. */
    public const FORM = 'one_click';

    /**
     * The refusals are the interesting half here.
     *
     * The form swaps itself for the answer, and HTMX does not swap a 4xx at
     * all — so a `ValidationException`, which is what every refusal on this
     * path is, went into the browser's console and the customer watched
     * nothing happen. Invisible while the only refusals were a honeypot
     * (answered as a success on purpose) and a rate limit nobody hits twice;
     * an everyday event the moment a CAPTCHA is switched on. So the answer to
     * an HTMX request is the same form again, carrying the message and what
     * was typed, with a 200 — the shape the admin's inline product editor
     * already uses, and the reason it is not a global HTMX handler is that a
     * theme which did not copy the handler would lose it again in silence.
     */
    public function store(
        Request $request,
        OneClick $oneClick,
        PlaceOrder $placer,
        CouponManager $coupons,
        FormGuard $guard,
    ) {
        abort_unless($this->forCart($request) ? $oneClick->isAvailableForCart() : $oneClick->isAvailable(), 404);

        try {
            return $this->place($request, $oneClick, $placer, $coupons, $guard);
        } catch (ValidationException $e) {
            $product = $this->forCart($request) ? null : Product::reachable()->find((int) $request->input('product_id'));

            // Without HTMX this is Laravel's own redirect-back-with-errors —
            // and so it is when there is no product to draw a form around
            // (the cart's form has none and needs none).
            if (! $request->header('HX-Request') || (! $product && ! $this->forCart($request))) {
                throw $e;
            }

            return response()->view('torgash.checkout::partials.one-click-form', [
                'product' => $product,
                'oneClickBuyForm' => $request->input('buy_form'),
                'oneClickError' => $e->validator->errors()->first(),
                // Explicitly, never old(): nothing was redirected, so nothing
                // was flashed, and a wrong sum must not cost them the phone
                // number they just typed.
                'oneClickValues' => $request->all(),
            ]);
        }
    }

    protected function place(
        Request $request,
        OneClick $oneClick,
        PlaceOrder $placer,
        CouponManager $coupons,
        FormGuard $guard,
    ) {
        $forCart = $this->forCart($request);

        $validated = $request->validate([
            'product_id' => [$forCart ? 'nullable' : 'required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'phone' => ['required', 'string', 'max:40'],
            // What was chosen in the buy box — the module that configures
            // this product reads and refuses it, exactly as on "Add to cart".
            'config' => ['nullable', 'array'],
        ]);

        // reachable(), not listed(): the button was rendered on a page this
        // visitor could open, and a product hidden from listings is still for
        // sale by its own link.
        $product = $forCart ? null : Product::reachable()->findOrFail($validated['product_id']);

        $identity = 'phone:'.substr((string) preg_replace('/\D/', '', $validated['phone']), -9);

        if ($refusal = $guard->check($request, $identity, self::FORM)) {
            // A trap hit is told everything went fine and nothing is written —
            // the same answer the enquiry form gives, for the same reason.
            if ($refusal['reason'] === 'trap') {
                return $this->done($request, null);
            }

            throw ValidationException::withMessages(['one_click' => $refusal['message']]);
        }

        // One click is the form competitors fill with other people's numbers
        // (PLAN §44): when the shop asks, the phone is confirmed by a code first.
        if ($unconfirmed = app(\App\Core\Verification\FormVerification::class)->refusal($request, self::FORM, ['phone' => $validated['phone']])) {
            throw ValidationException::withMessages(['phone' => $unconfirmed]);
        }

        // The basket itself on the cart page (what was asked for), a
        // throwaway one holding the product anywhere else.
        $cart = $forCart ? app(CartManager::class) : $oneClick->cartFor($product->id, (int) ($validated['qty'] ?? 1), (array) ($validated['config'] ?? []));

        if ($forCart && ! $oneClick->offersForCart($cart)) {
            throw ValidationException::withMessages([
                'one_click' => __('Something in the cart cannot be ordered this way. Please use the full checkout.'),
            ]);
        }

        $quote = $oneClick->quote($cart, $coupons);

        if (! $oneClick->offersNominated($quote)) {
            if (! $forCart) {
                $oneClick->discard($cart);
            }

            throw ValidationException::withMessages([
                'one_click' => __('We cannot take this order without an address. Please use the full checkout.'),
            ]);
        }

        $order = $placer->place(
            [
                // No name typed is stored as none, never as words in this
                // buyer's language (Order::customerLabel() says it on screen).
                'customer_name' => $validated['customer_name'] ?: auth()->user()?->name,
                // An order has to be addressable somehow, and this buyer gave a
                // phone. CustomerRef makes the phone the identity; the email
                // column stays empty rather than being filled with something
                // invented, which is exactly what older engines do here instead.
                'email' => auth()->user()?->email,
                'phone' => $validated['phone'],
                'shipping_method_id' => $oneClick->shippingMethodId(),
                'payment_method_id' => $oneClick->paymentMethod()->id,
            ],
            $cart,
            $coupons,
            new Location,
            $quote,
        );

        if (! $forCart) {
            $oneClick->discard($cart);
        }

        $request->session()->put('last_order_number', $order->order_number);

        // The basket is empty now, so the cart page behind the dialog is
        // wrong: the order's own page answers instead, as it does for the
        // full checkout.
        if ($forCart) {
            $confirmation = route('checkout.confirmation', $order->order_number);

            return $request->header('HX-Request')
                ? response('', 200, ['HX-Redirect' => $confirmation])
                : redirect()->to($confirmation);
        }

        return $this->done($request, $order->order_number);
    }

    /** Asked from the cart page, for the whole basket. */
    protected function forCart(Request $request): bool
    {
        return $request->input('scope') === 'cart';
    }

    protected function done(Request $request, ?string $orderNumber)
    {
        $message = $orderNumber
            ? __('Order :number placed. We will call you back to confirm it.', ['number' => $orderNumber])
            : __('Thank you — we will call you back.');

        if ($request->header('HX-Request')) {
            return response()->view('torgash.checkout::partials.one-click-done', ['message' => $message]);
        }

        return back()->with('status', $message);
    }
}

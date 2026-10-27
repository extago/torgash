<?php

namespace Torgash\Giftcards\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Cart\CartManager;
use Torgash\Giftcards\Codes;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Rules;
use Torgash\Giftcards\Wallet;

class GiftcardController extends Controller
{
    /**
     * The card's own page: what it is worth, what is left, from whom, the
     * words, the code behind a button. Addressed by a random token — the link
     * the letter carries and the one a buyer prints — never by the code.
     */
    public function show(string $token, Rules $rules)
    {
        $card = Card::where('public_token', $token)->firstOrFail();

        return view('torgash.giftcards::storefront.card', [
            'card' => $card,
            'rulesSaid' => $rules->describe($card),
            'qr' => $this->qr($card->code()),
        ]);
    }

    /**
     * The code as a QR picture, for a till's scanner — inline SVG through the
     * library the project already carries (bacon/bacon-qr-code, BSD-2), and
     * nothing at all where it is absent: the code is printed beside it anyway.
     */
    protected function qr(string $code): string
    {
        if (! class_exists(\BaconQrCode\Writer::class)) {
            return '';
        }

        $writer = new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle(160, 0),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd,
        ));

        return preg_replace('/^<\?xml.*?\?>\s*/s', '', $writer->writeString($code)) ?? '';
    }

    /** "Keep it in my account": from now on only this account can spend it. */
    public function claim(string $token)
    {
        $card = Card::where('public_token', $token)->firstOrFail();

        if ($card->owner_user_id && $card->owner_user_id !== auth()->id()) {
            return back()->with('giftcard_error', __('This gift card belongs to another account.'));
        }

        $card->forceFill(['owner_user_id' => auth()->id()])->save();

        return redirect()->route('account.giftcards.index')->with('status', __('The gift card :card is in your account now.', ['card' => $card->masked()]));
    }

    /** "Put it on my basket" from the card's own page. */
    public function useOnCart(string $token, CartManager $cart, Wallet $wallet)
    {
        $card = Card::where('public_token', $token)->firstOrFail();
        $result = $wallet->attach($cart, $card);

        return redirect()->route('cart.index')->with('coupon_result', $result);
    }

    public function remove(Request $request, int $card, CartManager $cart, Wallet $wallet)
    {
        $wallet->remove($cart, $card);

        return $this->answer($request, $cart);
    }

    /** "Take no more than …" — blank means as much as it can. */
    public function limit(Request $request, int $card, CartManager $cart, Wallet $wallet)
    {
        $typed = str_replace([' ', ','], ['', '.'], (string) $request->input('limit', ''));
        $wallet->limit($cart, $card, is_numeric($typed) ? (int) round((float) $typed * 100) : null);

        return $this->answer($request, $cart);
    }

    public function useOwn(Request $request, int $card, CartManager $cart, Wallet $wallet)
    {
        $found = Card::where('owner_user_id', auth()->id())->findOrFail($card);
        session()->flash('coupon_result', $wallet->attach($cart, $found));

        return $this->answer($request, $cart);
    }

    /** The account's cards, and a box to add one by its code. */
    public function account()
    {
        return view('torgash.giftcards::account.index', [
            'cards' => Card::where('owner_user_id', auth()->id())->with(['entries' => fn ($q) => $q->limit(50)])->orderByDesc('id')->get(),
        ]);
    }

    public function attach(Request $request)
    {
        $request->validate(['code' => 'required|string|max:40']);
        $key = 'giftcards:'.$request->ip();

        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, Wallet::TRIES)) {
            return back()->withErrors(['code' => __('Too many attempts. Try again in :minutes min.', [
                'minutes' => (int) ceil(\Illuminate\Support\Facades\RateLimiter::availableIn($key) / 60),
            ])]);
        }

        $card = Card::findByCode((string) $request->input('code'));

        if (! $card) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 900);
            hook_action('core.code.failed', ['kind' => 'giftcard', 'request' => $request]);

            return back()->withErrors(['code' => __('There is no gift card with this code.')])->withInput();
        }

        if ($card->owner_user_id && $card->owner_user_id !== auth()->id()) {
            return back()->withErrors(['code' => __('This gift card belongs to another account.')]);
        }

        if ($card->state() === 'pending') {
            return back()->withErrors(['code' => __('This gift card has not been activated yet.')]);
        }

        $card->forceFill(['owner_user_id' => auth()->id()])->save();

        return back()->with('status', __('The gift card :card is in your account now.', ['card' => $card->masked()]));
    }

    /**
     * The cart page swaps its contents; the checkout re-quotes its summary
     * when told the code box changed — the coupon controller's own answer.
     */
    protected function answer(Request $request, CartManager $cart)
    {
        if ($request->input('in') === 'checkout') {
            return $request->header('HX-Request')
                ? response('', 200, ['HX-Trigger' => 'coupon-changed'])
                : redirect()->route('checkout.create');
        }

        return $request->header('HX-Request')
            ? view('torgash.cart::partials.contents', ['cart' => $cart])
            : redirect()->route('cart.index');
    }
}

<?php

namespace Torgash\Giftcards\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Torgash\Giftcards\Models\Card;
use Torgash\Giftcards\Wallet;

/**
 * "What is left on this card?" for a client of the shop API (PLAN §40.4) — by
 * code, under the very limit the basket's box counts wrong codes against, so a
 * public endpoint is not a faster way to guess.
 */
class BalanceController extends Controller
{
    public function show(Request $request, string $code)
    {
        $key = 'giftcards:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, Wallet::TRIES)) {
            return response()->json(['message' => __('Too many attempts. Try again in :minutes min.', [
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ])], 429);
        }

        $card = Card::findByCode($code);

        if (! $card) {
            RateLimiter::hit($key, 900);
            hook_action('core.code.failed', ['kind' => 'giftcard', 'request' => $request]);

            return response()->json(['message' => __('There is no gift card with this code.')], 404);
        }

        return response()->json(['data' => [
            'last4' => $card->last4,
            'state' => $card->state(),
            'balance' => (int) $card->balance,
            'currency' => shop_currency(),
            'expires_at' => $card->expires_at?->toIso8601String(),
        ]]);
    }
}

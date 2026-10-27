<?php

namespace Torgash\Payments;

use App\Core\Support\Currencies;
use Torgash\Checkout\Models\PaymentMethod;

/**
 * Everything a bound gateway would fail on, in words.
 *
 * One class because there are two readers and they must not drift: the payment
 * method's form says it while the admin can still change it, and
 * `Invariants\PaymentInvariants` says it about a shop nobody is looking at.
 * A second copy of the currency comparison would eventually answer differently
 * from the form, and then the screen and the watch would be arguing about
 * whether a method can take money.
 *
 * The currency check lives here rather than inside each gateway because it is
 * the same comparison every time — what the acquiring takes against what the
 * shop charges in. Those are genuinely two axes: a shop lists in dollars and is
 * paid in hryvnia, or the reverse, and nothing before this said a word about
 * the combination until a customer stood in front of a Pay button that could
 * not work.
 */
class Readiness
{
    /**
     * Each warning is a translation KEY and its parameters, never a finished
     * sentence: the form translates them for whoever is looking, and the
     * nightly invariant run stores them for somebody who may read the screen in
     * another language — the rule findings and background tasks both live by.
     *
     * A gateway's OWN complaints are the exception the interface forces: it
     * hands back sentences it has already translated, and they are carried
     * through as keys with no parameters. `__()` gives such a string straight
     * back, so nothing is lost, and a gateway that wants the full treatment can
     * return its key instead of translating it.
     *
     * @param  array<string, mixed>  $config  what is stored for this gateway, or [] while it is only being looked at
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    public function warnings(Gateway $gateway, PaymentMethod $method, array $config): array
    {
        $warnings = array_map(fn ($own) => [(string) $own, []], $gateway->warnings($config));
        $takes = $gateway->currencies();

        if ($takes === null) {
            return $warnings;
        }

        $takes = array_map('strtoupper', $takes);

        // What this METHOD would hand the gateway, which is not simply the
        // shop's currency list: the method says which currencies it accepts,
        // and which one it converts everything else into. A shop pricing in
        // dollars and charging in hryvnia is correctly set up, and warning it
        // about dollars would be noise.
        if ($method->charge_currency) {
            return in_array(strtoupper($method->charge_currency), $takes, true)
                ? $warnings
                : [...$warnings, ['This method charges in :charge, which this gateway cannot take — it takes :takes.', [
                    'charge' => strtoupper($method->charge_currency), 'takes' => implode(', ', $takes),
                ]]];
        }

        $accepted = array_map('strtoupper', array_filter((array) $method->currencies))
            ?: array_keys(app(Currencies::class)->all());
        $unsupported = array_values(array_diff($accepted, $takes));

        if ($unsupported === []) {
            return $warnings;
        }

        // Every currency unsupported is a different sentence from some: the
        // first is a method that can never take a payment, the second is one
        // that works for part of the shop.
        return [...$warnings, count($unsupported) === count($accepted)
            ? ['This gateway takes :takes, and this method accepts :shop — no order could be paid with it.', [
                'takes' => implode(', ', $takes), 'shop' => implode(', ', $accepted),
            ]]
            : ['Orders in :list cannot be paid with this gateway — it takes :takes. Give the method a currency to charge in, or stop accepting them.', [
                'list' => implode(', ', $unsupported), 'takes' => implode(', ', $takes),
            ]]];
    }
}

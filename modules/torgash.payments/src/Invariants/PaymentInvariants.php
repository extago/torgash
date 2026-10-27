<?php

namespace Torgash\Payments\Invariants;

use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Payments\Gateways;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Readiness;

/**
 * What must be true about taking money, said by the module that does it.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the guard
 * being installed.
 *
 * Both statements are about a Pay button that cannot work, which is the
 * shipping module's silent failure seen from the other end of the checkout: the
 * order is placed in `awaiting_payment`, the customer is sent to a gateway that
 * is not there or cannot take their currency, and what the shop sees is an
 * order that was never paid — indistinguishable from one the customer changed
 * their mind about. Nothing anywhere says the wiring is broken.
 *
 * A gateway's own complaints (no key, test mode left on where it matters) come
 * from `Readiness`, the same class the method's form prints them from. One
 * formula, two readers — the alternative is a screen and a watch that disagree
 * about whether the shop can be paid.
 */
class PaymentInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            // The binding stores a KEY, and a key outlives the module that
            // answered for it: disabling a gateway's module leaves every method
            // bound to it pointing at nothing, while the method itself still
            // looks perfectly configured on its own screen.
            'payments.gateways_are_present' => [
                'label' => 'Every payment method wired to a gateway still has that gateway',
                'explain' => 'The method is wired to a gateway no module answers for any more — usually one that has been disabled or uninstalled. An order placed with it waits for a payment nothing can take. Install the gateway\'s module again, wire the method to another gateway, or switch the method off.',
                'check' => function (int $limit) {
                    $known = array_keys(app(Gateways::class)->all());

                    return Binding::query()
                        ->when($known !== [], fn ($q) => $q->whereNotIn('gateway', $known))
                        ->orderBy('payment_method_id')
                        ->limit($limit)
                        ->get()
                        ->map(function (Binding $binding) {
                            $method = $binding->method();

                            return [
                                'id' => (string) $binding->payment_method_id,
                                'title' => $method?->t('name') ?: '#'.$binding->payment_method_id,
                                'detail' => ['Wired to ":key", which nothing answers for.', ['key' => (string) $binding->gateway]],
                                'url' => $method
                                    ? route('admin.checkout.payment-methods.edit', $method->id)
                                    : route('admin.checkout.payment-methods.index'),
                            ];
                        });
                },
            ],

            // Said on the form while somebody is looking at it, and here for
            // the rest of the time. A shop sets a gateway up once and then
            // changes its currencies, or its base currency, six months later on
            // a different screen — and nothing about that edit passes anywhere
            // near this comparison.
            'payments.gateways_can_charge' => [
                'label' => 'Every payment method with a gateway could actually be paid',
                'explain' => 'The gateway behind this method reports something that would stop a payment — a credential it has not been given, or a currency it cannot take. Open the payment method and read the note under the gateway; this is the same check its form runs, made about a shop nobody is looking at.',
                'check' => function (int $limit) {
                    $gateways = app(Gateways::class);
                    $readiness = app(Readiness::class);
                    $found = [];

                    // Enabled methods only, and the bindings are read through
                    // them rather than the other way round: a method the shop
                    // has switched off is not offered to anybody, and reporting
                    // its unfinished gateway is exactly the noise that gets a
                    // screen stopped being read.
                    $methods = PaymentMethod::query()->where('enabled', true)->orderBy('id')->get();
                    $bindings = Binding::whereIn('payment_method_id', $methods->pluck('id'))->get()->keyBy('payment_method_id');

                    foreach ($methods as $method) {
                        if (count($found) >= $limit) {
                            break;
                        }

                        $binding = $bindings[$method->id] ?? null;
                        $gateway = $binding ? $gateways->find($binding->gateway) : null;

                        // A missing gateway is the statement above; saying it
                        // twice would have the shop fix one finding and watch
                        // another stay.
                        if (! $gateway) {
                            continue;
                        }

                        $warnings = $readiness->warnings($gateway, $method, (array) ($binding->config ?? []));

                        if ($warnings === []) {
                            continue;
                        }

                        // The first one, as the key and parameters Readiness
                        // hands back: a finding holds one sentence, the form
                        // shows them all, and storing a joined string would
                        // freeze it in the language the nightly run happened to
                        // speak.
                        $found[] = [
                            'id' => (string) $method->id,
                            'title' => $method->t('name') ?: '#'.$method->id,
                            'detail' => $warnings[0],
                            'url' => route('admin.checkout.payment-methods.edit', $method->id),
                        ];
                    }

                    return $found;
                },
            ],
        ];
    }
}

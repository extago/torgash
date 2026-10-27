<?php

namespace Torgash\Payments\Admin;

use Illuminate\Http\Request;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Payments\Gateways;
use Torgash\Payments\Models\Binding;
use Torgash\Payments\Readiness;

/**
 * The gateway section inside the payment method form.
 *
 * The modules are separate — checkout owns the method a customer picks, this
 * one owns taking the money — but the SCREEN must not be. A shop owner thinks
 * "set up payments", not "and now go to the other page and connect the same
 * thing again", and older engines are right about that even where they are wrong about
 * everything else. So the split lives in the code and nowhere in the UI.
 *
 * Every gateway's fields are rendered at once and shown by the picker, rather
 * than reloading the page to reveal them: the form belongs to checkout, and
 * making it submit itself to display a field would save a half-filled method.
 * That is why the inputs are namespaced per gateway (`gateway_config[<key>][…]`)
 * — a hidden gateway's inputs are still posted, and only the selected one's are
 * read.
 *
 * What a gateway would fail on is asked of `Readiness`, not worked out here:
 * the same sentences are said about a shop nobody is looking at by
 * `Invariants\PaymentInvariants`, and two copies of the currency comparison
 * would end up disagreeing about whether a method can take money.
 */
class MethodGatewaySection
{
    public function __construct(protected Gateways $gateways) {}

    public function render(PaymentMethod $method): string
    {
        $options = $this->gateways->options();

        if ($options === []) {
            return '';
        }

        $binding = $method->exists ? Binding::where('payment_method_id', $method->id)->first() : null;

        $fields = [];
        $warnings = [];
        foreach (array_keys($options) as $key) {
            $gateway = $this->gateways->find($key);
            $fields[$key] = $gateway?->fields() ?? [];
            $warnings[$key] = $gateway
                ? app(Readiness::class)->warnings($gateway, $method, $binding?->gateway === $key ? ($binding->config ?? []) : [])
                : [];
        }

        return view('torgash.payments::partials.method-section', [
            'method' => $method,
            'binding' => $binding,
            'options' => $options,
            'fields' => $fields,
            'warnings' => $warnings,
        ])->render();
    }

    public function save(PaymentMethod $method, Request $request): void
    {
        $key = (string) $request->input('gateway', '');

        // Unwiring removes the row rather than blanking it: a half-configured
        // leftover is what a future gateway of the same key would inherit.
        if ($key === '' || ! isset($this->gateways->all()[$key])) {
            Binding::where('payment_method_id', $method->id)->delete();

            return;
        }

        $binding = Binding::firstOrNew(['payment_method_id' => $method->id]);
        $config = $binding->gateway === $key ? ($binding->config ?? []) : [];

        foreach ($this->gateways->find($key)->fields() as $name => $field) {
            $type = $field['type'] ?? 'text';
            $value = $request->input("gateway_config.{$key}.{$name}");

            // A blank credential means "keep the one you have": the form cannot
            // show it back, so saving the page must not wipe a working key just
            // because nobody retyped it.
            if ($type === 'password' && (string) $value === '') {
                continue;
            }

            $config[$name] = $type === 'checkbox'
                ? $request->boolean("gateway_config.{$key}.{$name}")
                : $value;
        }

        $binding->fill([
            'gateway' => $key,
            'config' => $config,
            'test_mode' => $request->boolean('gateway_test_mode'),
        ])->save();
    }
}

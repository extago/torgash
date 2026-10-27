<?php

namespace App\Http\Controllers;

use App\Core\Verification\Channels;
use App\Core\Verification\FormVerification;
use App\Core\Verification\Verifier;
use App\Http\Controllers\Account\CodeController;
use Illuminate\Http\Request;

/**
 * The widget that confirms a form's contact by a code (FormVerification,
 * `<x-contact-verify>`). It reads the address from the form's own field, so
 * one widget serves the checkout, a one-click dialog and a callback alike;
 * it answers only with its own fragment. Sending goes through the Verifier
 * with all its limits — this endpoint sends on demand, which is exactly what
 * gets abused — and a code that came back is remembered in this browser.
 * Whether the form may go is decided again by the form's own controller.
 */
class ContactVerifyController extends Controller
{
    public function __construct(protected Verifier $verifier, protected Channels $channels, protected FormVerification $forms) {}

    public function send(Request $request)
    {
        [$form, $kind, $address, $field] = $this->read($request);

        if ($address === null) {
            return $this->widget($form, $field, 'idle', $kind, error: $kind === 'phone'
                ? __('Type a phone number first — with the country code if it is not local.')
                : __('Type an email first.'));
        }

        if ($this->forms->verified($request, $kind, $address)) {
            return $this->widget($form, $field, 'done', $kind, $address);
        }

        $row = $this->verifier->current($request, FormVerification::PURPOSE);

        // Same address, a code already on its way: that is a resend.
        if ($row && $row->isOpen() && $row->address === $address) {
            $error = $this->verifier->resend($request, $row) ?? CodeController::sendingProblem($row);

            return $this->widget($form, $field, 'code', $kind, $address, $error, $this->verifier->secondsUntilResend($row));
        }

        $channel = array_key_first($this->channels->for($kind, $address));

        if (! $channel) {
            return $this->widget($form, $field, 'idle', $kind, error: __('The shop cannot send a code to this address. Check it, or try another one.'));
        }

        $started = $this->verifier->start($request, FormVerification::PURPOSE, $kind, $address, $channel, $request->user());

        if ($started['error'] || ($problem = CodeController::sendingProblem($started['code']))) {
            return $this->widget($form, $field, 'idle', $kind, error: $started['error'] ?? $problem);
        }

        return $this->widget($form, $field, 'code', $kind, $address, null, $this->verifier->secondsUntilResend($started['code']));
    }

    public function check(Request $request)
    {
        [$form, $kind, $address, $field] = $this->read($request);
        $row = $this->verifier->current($request, FormVerification::PURPOSE);

        if (! $row || $address === null || $row->address !== $address) {
            return $this->widget($form, $field, 'idle', $kind);
        }

        if ($error = $this->verifier->check($row, (string) $request->input('verify_code'))) {
            return $this->widget($form, $field, 'code', $kind, $address, $error, $this->verifier->secondsUntilResend($row));
        }

        $this->forms->remember($request, $kind, $address);
        $this->verifier->forget($request, FormVerification::PURPOSE);

        return $this->widget($form, $field, 'done', $kind, $address);
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: string} */
    protected function read(Request $request): array
    {
        $form = (string) $request->input('verify_form');
        abort_unless(array_key_exists($form, FormVerification::forms()), 404);

        abort_unless($this->forms->kindFor($form) !== null, 404);

        // The form's own fields are read by their names — `phone`/`email`
        // unless the widget was told otherwise — and its own country field,
        // when it has one (checkout does). The contact confirmed is the one
        // the person typed (FormVerification::pick).
        $names = (array) json_decode((string) $request->input('verify_fields', '{}'), true);
        $contacts = [];
        foreach (['phone', 'email'] as $kind) {
            $name = (string) ($names[$kind] ?? $kind);
            abort_unless(preg_match('/^[a-z_\[\]]+$/i', $name), 404);
            $contacts[$kind] = (string) data_get($request->all(), str_replace(['[', ']'], ['.', ''], $name));
        }

        [$kind, $address] = $this->forms->pick($form, $contacts, $request->input('country'));

        return [$form, $kind, $address, (string) json_encode($names)];
    }

    protected function widget(string $form, string $field, string $state, string $kind, ?string $address = null, ?string $error = null, int $wait = 0)
    {
        return view('components.contact-verify-state', compact('form', 'field', 'state', 'kind', 'address', 'error', 'wait'));
    }
}

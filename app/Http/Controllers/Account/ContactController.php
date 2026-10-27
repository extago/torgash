<?php

namespace App\Http\Controllers\Account;

use App\Core\Support\Phones;
use App\Core\Verification\Channels;
use App\Core\Verification\Verifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Changing the email or the phone of an account (PLAN §44.3, the owner's
 * mockups): a code goes to the NEW address, and only typing it back writes
 * it — which is what makes a stored address a proven one, and a phone
 * usable for signing in and getting back in. An address another account
 * already has is refused before anything is sent ("already bound to another
 * account"). The OLD email is told when the email changes: if somebody else
 * did it, that letter is how the owner of the account finds out.
 *
 * A phone is offered only while some channel can carry a code to one — a
 * shop without SMS keeps its account screen exactly as it was.
 */
class ContactController extends Controller
{
    public const KINDS = ['email', 'phone'];

    public function __construct(protected Verifier $verifier, protected Channels $channels) {}

    public function edit(Request $request, string $kind)
    {
        $this->kind($kind);

        return view('account.contact', [
            'kind' => $kind,
            'user' => $request->user(),
            'country' => Phones::defaultCountry(),
        ]);
    }

    public function store(Request $request, string $kind)
    {
        $this->kind($kind);
        $user = $request->user();

        if ($kind === 'email') {
            $address = Verifier::normalise('email', (string) $request->validate(['address' => ['required', 'email', 'max:190']])['address']);
            $taken = User::whereRaw('lower(email) = ?', [$address])->where('id', '!=', $user->id)->exists();
            $same = strcasecmp((string) $user->email, $address) === 0;
        } else {
            $phone = Phones::parse((string) $request->validate(['address' => ['required', 'string', 'max:40']])['address']);

            if (! $phone) {
                throw ValidationException::withMessages(['address' => __('This does not look like a phone number. Type it with the country code, like :example.', ['example' => Phones::callingCode(Phones::defaultCountry()).' …'])]);
            }

            $address = $phone['e164'];
            $taken = User::where('phone', $address)->where('id', '!=', $user->id)->exists();
            $same = $user->phone === $address && $user->phone_verified_at;
        }

        if ($same) {
            throw ValidationException::withMessages(['address' => __('This is already the address of your account.')]);
        }

        if ($taken) {
            throw ValidationException::withMessages(['address' => __('This address is already bound to another account. Type another one.')]);
        }

        $channel = array_key_first($this->channels->for($kind, $address));

        if (! $channel) {
            throw ValidationException::withMessages(['address' => $kind === 'phone'
                ? __('The shop cannot send a code to this number. Check it, or try another one.')
                : __('This way of sending a code is not available for this address.')]);
        }

        $started = $this->verifier->start($request, $this->purpose($kind), $kind, $address, $channel, $user);

        if ($started['error']) {
            throw ValidationException::withMessages(['address' => $started['error']]);
        }

        if ($message = CodeController::sendingProblem($started['code'])) {
            throw ValidationException::withMessages(['address' => $message]);
        }

        return redirect()->route('account.contact.enter', $kind);
    }

    public function enter(Request $request, string $kind)
    {
        $this->kind($kind);
        $row = $this->verifier->current($request, $this->purpose($kind));

        if (! $row || ! $row->isOpen() || $row->user_id !== $request->user()->id) {
            return redirect()->route('account.contact', $kind);
        }

        return view('account.code-enter', [
            'purpose' => $this->purpose($kind),
            'row' => $row,
            'masked' => $kind === 'phone' ? Phones::display($row->address) : $row->address,
            'wait' => $this->verifier->secondsUntilResend($row),
            'channels' => $this->channels->for($row->kind, $row->address),
            'verifyRoute' => route('account.contact.verify', $kind),
            'resendRoute' => route('account.contact.resend', $kind),
            'backRoute' => route('account.contact', $kind),
        ]);
    }

    public function verify(Request $request, string $kind)
    {
        $this->kind($kind);
        $user = $request->user();
        $row = $this->verifier->current($request, $this->purpose($kind));

        if (! $row || $row->user_id !== $user->id) {
            return redirect()->route('account.contact', $kind);
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];

        if ($error = $this->verifier->check($row, $code)) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        $old = $user->email;

        try {
            $kind === 'email'
                ? $user->forceFill(['email' => $row->address, 'email_verified_at' => now()])->save()
                : $user->forceFill(['phone' => $row->address, 'phone_country' => Phones::parse($row->address)['country'] ?? null, 'phone_verified_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            // Taken between the code going out and coming back.
            return redirect()->route('account.contact', $kind)->withErrors(['address' => __('This address is already bound to another account. Type another one.')]);
        }

        $this->verifier->forget($request, $this->purpose($kind));

        if ($kind === 'email' && $old && strcasecmp($old, $row->address) !== 0) {
            \App\Core\Mail\Letters::send('account.email_changed', $old, ['name' => $user->name, 'new_email' => $row->address]);
        }

        return redirect()->route('account.dashboard')->with('status', $kind === 'email'
            ? __('Your email is changed and confirmed.')
            : __('Your phone is confirmed. You can sign in with it now.'));
    }

    public function resend(Request $request, string $kind)
    {
        $this->kind($kind);
        $row = $this->verifier->current($request, $this->purpose($kind));

        if (! $row || $row->user_id !== $request->user()->id) {
            return redirect()->route('account.contact', $kind);
        }

        $channel = $request->validate(['channel' => ['nullable', 'string', 'max:60']])['channel'] ?? null;

        if ($error = $this->verifier->resend($request, $row, $channel)) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        if ($message = CodeController::sendingProblem($row)) {
            throw ValidationException::withMessages(['code' => $message]);
        }

        return redirect()->route('account.contact.enter', $kind)->with('status', __('A new code is on its way.'));
    }

    /** Taking the phone off the account — nothing to prove to remove it. */
    /**
     * Letting go of a contact (PLAN §44, 3b) — only while what is left still
     * satisfies the shop's rule and still signs in (AccountContact): the last
     * way into an account is never removed, whichever one it is.
     */
    public function destroy(Request $request, string $kind = 'phone')
    {
        $this->kindKnown($kind);
        $user = $request->user();

        if ($why = app(\App\Core\Access\AccountContact::class)->removalRefusal($user, $kind)) {
            return redirect()->route('account.dashboard')->withErrors(['contact' => $why]);
        }

        $user->forceFill($kind === 'email'
            ? ['email' => null, 'email_verified_at' => null]
            : ['phone' => null, 'phone_country' => null, 'phone_verified_at' => null])->save();

        return redirect()->route('account.dashboard')->with('status', $kind === 'email'
            ? __('The email is removed from your account.')
            : __('The phone is removed from your account.'));
    }

    public function destroyEmail(Request $request)
    {
        return $this->destroy($request, 'email');
    }

    /** Removing needs no channel: an address can be let go of without a code. */
    protected function kindKnown(string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
    }

    protected function purpose(string $kind): string
    {
        return $kind.'_change';
    }

    protected function kind(string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        abort_if($kind === 'phone' && ! $this->channels->offers('phone'), 404);
    }
}

<?php

namespace App\Http\Controllers\Account;

use App\Core\Support\FormGuard;
use App\Core\Support\Phones;
use App\Core\Verification\Channels;
use App\Core\Verification\SendResult;
use App\Core\Verification\Verifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Signing in with a code, and getting back into an account whose password is
 * lost — the same act (PLAN §44.3): prove an address, and you are in. The
 * only difference is where you land: a restored account is asked for a new
 * password first.
 *
 * The person types an email OR a phone; the account is found by either (a
 * phone only once it was proven). Then every way the ACCOUNT can be reached
 * is offered — its email by email, its proven phone by every channel that
 * serves that number — masked, so the choice shows which inbox or which
 * phone without saying the address. One way: it is used without asking.
 *
 * Signing in by code is the shop's choice (`core.sign_in_by_code`, off by
 * default). Restoring access is always there — an account nobody can get
 * back into is an account the shop has lost. Customers only: staff sign in
 * through the admin, whose door carries the second factor.
 */
class CodeController extends Controller
{
    public const FORM = 'account.code';

    public const PURPOSES = ['sign_in', 'password_reset'];

    public function __construct(protected Verifier $verifier, protected Channels $channels) {}

    public function create(Request $request)
    {
        $purpose = $this->purpose($request);

        return view('account.code-request', [
            'purpose' => $purpose,
            'login' => (string) $request->query('login', $request->query('email', '')),
            'phones' => $this->channels->offers('phone'),
            'options' => session('code_options', []),
        ]);
    }

    public function store(Request $request, FormGuard $guard)
    {
        $purpose = $this->purpose($request);
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'via' => ['nullable', 'string', 'max:120'],
        ]);
        $login = trim($data['login']);

        // The second pass (which way to send) is the same form drawn again a
        // second ago: its fresh age stamp would read as "too fast". The login
        // already passed the guard on the first pass, so it is not asked twice.
        $chosen = ($data['via'] ?? null) && $request->session()->pull('code_pending') === mb_strtolower($login);

        if (! $chosen && ($refusal = $guard->check($request, mb_strtolower($login), self::FORM))) {
            throw ValidationException::withMessages(['login' => $refusal['message']]);
        }

        $user = $this->findAccount($request, $guard, $login);
        $options = $this->options($user);

        if ($options === []) {
            throw ValidationException::withMessages(['login' => __('There is no way to send a code to this account right now. Contact the shop.')]);
        }

        $via = $data['via'] ?? (count($options) === 1 ? array_key_first($options) : null);

        if (! $via || ! isset($options[$via])) {
            // Several ways: the same form again, now asking which.
            $request->session()->put('code_pending', mb_strtolower($login));

            return back()->withInput()->with('code_options', array_map(fn ($o) => $o['label'], $options));
        }

        ['kind' => $kind, 'address' => $address, 'channel' => $channel] = $options[$via];
        $started = $this->verifier->start($request, $purpose, $kind, $address, $channel, $user);

        if ($started['error']) {
            throw ValidationException::withMessages(['login' => $started['error']]);
        }

        $guard->record($request, mb_strtolower($login), self::FORM);

        if ($message = self::sendingProblem($started['code'])) {
            throw ValidationException::withMessages(['login' => $message]);
        }

        return redirect()->route('account.code.enter', ['for' => $purpose]);
    }

    public function enter(Request $request)
    {
        $purpose = $this->purpose($request);
        $row = $this->verifier->current($request, $purpose);

        if (! $row || ! $row->isOpen()) {
            return redirect()->route('account.code', ['for' => $purpose]);
        }

        return view('account.code-enter', [
            'purpose' => $purpose,
            'row' => $row,
            'masked' => Verifier::mask($row->kind, $row->address),
            'wait' => $this->verifier->secondsUntilResend($row),
            'channels' => $this->channels->for($row->kind, $row->address),
            'verifyRoute' => route('account.code.verify'),
            'resendRoute' => route('account.code.resend'),
            'backRoute' => route('account.code', ['for' => $purpose]),
        ]);
    }

    public function verify(Request $request)
    {
        $purpose = $this->purpose($request);
        $row = $this->verifier->current($request, $purpose);

        if (! $row) {
            return redirect()->route('account.code', ['for' => $purpose]);
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];

        if ($error = $this->verifier->check($row, $code)) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        return $this->signIn($request, $row);
    }

    public function resend(Request $request)
    {
        $purpose = $this->purpose($request);
        $row = $this->verifier->current($request, $purpose);

        if (! $row) {
            return redirect()->route('account.code', ['for' => $purpose]);
        }

        $channel = $request->validate(['channel' => ['nullable', 'string', 'max:60']])['channel'] ?? null;

        if ($error = $this->verifier->resend($request, $row, $channel)) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        if ($message = self::sendingProblem($row)) {
            throw ValidationException::withMessages(['code' => $message]);
        }

        return redirect()->route('account.code.enter', ['for' => $purpose])->with('status', __('A new code is on its way.'));
    }

    /**
     * The button in the letter: the row and its code, on whatever device the
     * letter was opened. The same check as typing it, attempts included.
     */
    public function link(Request $request, VerificationCode $code, string $secret)
    {
        if (! in_array($code->purpose, self::PURPOSES, true) || ! $code->user_id) {
            abort(404);
        }

        if ($error = $this->verifier->check($code, $secret)) {
            return redirect()->route('account.code', ['for' => $code->purpose])->withErrors(['login' => $error]);
        }

        return $this->signIn($request, $code);
    }

    /** What the customer is told when the channel did not take the code. */
    public static function sendingProblem(VerificationCode $row): ?string
    {
        return match ($row->status) {
            SendResult::INVALID_ADDRESS => $row->kind === 'phone'
                ? __('We could not send a code to this number — check that it is typed correctly.')
                : __('We could not send a code to this address — check that it is typed correctly.'),
            SendResult::UNREACHABLE => __('This way of sending cannot reach this address. Choose another one.'),
            SendResult::TEMPORARY => __('The code could not be sent just now. Try again in a minute.'),
            default => null,
        };
    }

    protected function signIn(Request $request, VerificationCode $row)
    {
        $user = $row->user;
        abort_unless($user && ! $user->roles()->exists(), 404);

        // The code proved the address — which is what "verified" means.
        if ($row->kind === 'email' && ! $user->email_verified_at && strcasecmp((string) $user->email, $row->address) === 0) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        auth()->login($user);
        $request->session()->regenerate();
        $this->verifier->forget($request, $row->purpose);

        if ($row->purpose === 'password_reset') {
            $request->session()->put(NewPasswordController::SESSION, now()->addMinutes(15)->timestamp);

            return redirect()->route('account.password.new');
        }

        return redirect()->intended(route('account.dashboard'));
    }

    /**
     * The customer an email or a phone belongs to. Said, not hidden, when
     * there is none (PLAN §44.2/2: a shop, not a social network) — and
     * counted like a sent code, so a script walking a list of addresses gets
     * that answer five times, not five thousand.
     */
    protected function findAccount(Request $request, FormGuard $guard, string $login): User
    {
        if (str_contains($login, '@')) {
            $email = mb_strtolower($login);
            $query = fn () => User::query()->whereRaw('lower(email) = ?', [$email]);
            $what = 'email';
        } else {
            $phone = Phones::parse($login);

            if (! $phone) {
                throw ValidationException::withMessages(['login' => __('This does not look like a phone number. Type it with the country code, like :example.', ['example' => Phones::callingCode(Phones::defaultCountry()).' …'])]);
            }

            $query = fn () => User::query()->where('phone', $phone['e164'])->whereNotNull('phone_verified_at');
            $what = 'phone';
        }

        if ($user = $query()->whereDoesntHave('roles')->first()) {
            return $user;
        }

        $guard->record($request, mb_strtolower($login), self::FORM);

        if ($query()->whereHas('roles')->exists()) {
            throw ValidationException::withMessages(['login' => __('This account signs in through the admin panel.')]);
        }

        $request->session()->flash('no_account', true);

        throw ValidationException::withMessages(['login' => $what === 'email'
            ? __('No account uses this email. Check it, or create an account.')
            : __('No account has this phone confirmed. Try your email, or create an account.')]);
    }

    /**
     * Every way this account can get a code, keyed `kind|channel`.
     *
     * @return array<string, array{kind: string, address: string, channel: string, label: string}>
     */
    protected function options(User $user): array
    {
        $options = [];

        foreach (array_filter([
            'email' => $user->email,
            'phone' => $user->phone_verified_at ? $user->phone : null,
        ]) as $kind => $address) {
            foreach ($this->channels->for($kind, $address) as $key => $channel) {
                $options[$kind.'|'.$key] = [
                    'kind' => $kind,
                    'address' => $address,
                    'channel' => $key,
                    'label' => __('By :way to :address', ['way' => __($channel->label()), 'address' => Verifier::mask($kind, $address)]),
                ];
            }
        }

        return $options;
    }

    protected function purpose(Request $request): string
    {
        $purpose = (string) $request->input('for', 'password_reset');

        abort_unless(in_array($purpose, self::PURPOSES, true), 404);
        abort_if($purpose === 'sign_in' && ! setting('core', 'sign_in_by_code'), 404);

        return $purpose;
    }
}

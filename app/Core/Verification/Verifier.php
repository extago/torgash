<?php

namespace App\Core\Verification;

use App\Core\Support\ClientAddress;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The one place a code is issued, re-sent and checked (PLAN §44.3). A form,
 * the checkout and the profile ask it; none of them knows how many attempts
 * a code has or when the next one may be sent.
 *
 * Six digits, ten minutes, five attempts. A code is bound to the BROWSER that
 * asked for it (a random token in its session, hashed on the row): typing it
 * anywhere else finds no code. The letter's own button carries the code, so
 * it works on the phone the letter was opened on — that is the same secret,
 * delivered to the same inbox. Sending is paced: a code again after 60 s,
 * then 120, 240 … up to 10 minutes, at most five per code, and per address
 * and per IP an hourly ceiling — sending costs money on every channel but
 * email, and a form that sends on demand is a form somebody will point at
 * premium-rate numbers.
 */
class Verifier
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SENDS = 5;

    public const PER_ADDRESS_HOURLY = 6;

    public const PER_IP_HOURLY = 20;

    protected const SESSION = 'verification';

    public function __construct(protected Channels $channels) {}

    /**
     * What each purpose is called, for the letter and the log. A module adds
     * its own through `core.verification.purposes`.
     *
     * @return array<string, string>
     */
    public static function purposes(): array
    {
        return (array) hook_filter('core.verification.purposes', [
            'sign_in' => 'Your code to sign in',
            'password_reset' => 'Your code to restore access to your account',
            'channel_test' => 'A test of a code channel from the admin',
            'email_change' => 'Your code to confirm the new email of your account',
            'phone_change' => 'Your code to confirm the phone of your account',
            FormVerification::PURPOSE => 'Your code to confirm your contact',
        ]);
    }

    /**
     * What each purpose is called in a LIST (the admin's log) — two words,
     * where purposes() is the line a letter opens with.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        return (array) hook_filter('core.verification.purpose_names', [
            'sign_in' => 'Sign-in',
            'password_reset' => 'Password restore',
            'channel_test' => 'Channel test',
            'email_change' => 'Email change',
            'phone_change' => 'Phone change',
            FormVerification::PURPOSE => 'Contact in a form',
        ]);
    }

    /** One line carrying the code — the SMS itself, the letter's subject. */
    public static function text(string $code): string
    {
        return __(':code is your code for :shop', ['code' => $code, 'shop' => current_storefront()?->name ?? config('app.name')]);
    }

    public static function purposeLabel(string $purpose): string
    {
        return __(self::purposes()[$purpose] ?? 'Your confirmation code');
    }

    /**
     * Issues a code and sends it. Returns the row (its `status` says how the
     * sending went) or an error the customer is shown — never both.
     *
     * @return array{code: ?VerificationCode, error: ?string}
     */
    public function start(Request $request, string $purpose, string $kind, string $address, string $channelKey, ?User $user = null): array
    {
        $address = self::normalise($kind, $address);
        $channel = $this->channels->for($kind, $address)[$channelKey] ?? null;

        if (! $channel) {
            return ['code' => null, 'error' => __('This way of sending a code is not available for this address.')];
        }

        if ($limit = $this->limited($request, $kind, $address)) {
            return ['code' => null, 'error' => $limit];
        }

        $row = VerificationCode::create([
            'purpose' => $purpose,
            'kind' => $kind,
            'address' => $address,
            'channel' => $channelKey,
            'code_hash' => '',
            'user_id' => $user?->id,
            'browser_hash' => $this->browserHash($request),
            'ip' => $request->ip(),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $request->session()->put(self::SESSION.'.'.$purpose, $row->id);

        $this->deliver($request, $row, $channel);

        return ['code' => $row, 'error' => null];
    }

    /**
     * A new code for the same address, by the same channel or another one.
     *
     * @return ?string an error, or null when a code went out
     */
    public function resend(Request $request, VerificationCode $row, ?string $channelKey = null): ?string
    {
        if ($row->verified_at) {
            return __('This code has already been used.');
        }

        if ($row->sends >= self::MAX_SENDS) {
            return __('Too many codes for this attempt. Start again a little later.');
        }

        if (($wait = $this->secondsUntilResend($row)) > 0) {
            return trans_choice('A new code can be sent in :count second.|A new code can be sent in :count seconds.', $wait);
        }

        $channelKey ??= $row->channel;
        $channel = $this->channels->for($row->kind, $row->address)[$channelKey] ?? null;

        if (! $channel) {
            return __('This way of sending a code is not available for this address.');
        }

        if ($limit = $this->limited($request, $row->kind, $row->address)) {
            return $limit;
        }

        $row->channel = $channelKey;
        $row->attempts = 0;
        $row->expires_at = now()->addMinutes(self::TTL_MINUTES);
        $this->deliver($request, $row, $channel);

        return null;
    }

    /**
     * Whether `$code` opens this row. A wrong one counts; the fifth closes it
     * and is announced as a failed sign-in, which is what the guard's blocking
     * counts. A right one closes it too — a code works once.
     *
     * @return ?string an error, or null when the code was right
     */
    public function check(VerificationCode $row, string $code): ?string
    {
        if ($row->verified_at) {
            return __('This code has already been used.');
        }

        if (! $row->isOpen() || $row->attempts >= self::MAX_ATTEMPTS) {
            return __('The code has expired. Ask for a new one.');
        }

        $code = preg_replace('/\D/', '', $code);

        if ($row->code_hash === '' || ! hash_equals($row->code_hash, $this->hash($row, $code))) {
            $row->increment('attempts');

            event(new Failed('web', $row->user, [$row->kind => $row->address]));

            return $row->attempts >= self::MAX_ATTEMPTS
                ? __('Too many wrong codes. Ask for a new one.')
                : __('That is not the code we sent.');
        }

        $row->forceFill(['verified_at' => now(), 'status' => 'verified'])->save();

        return null;
    }

    /** The code this browser is in the middle of for a purpose, if any. */
    public function current(Request $request, string $purpose): ?VerificationCode
    {
        $id = $request->session()->get(self::SESSION.'.'.$purpose);
        $row = $id ? VerificationCode::find($id) : null;

        return $row && hash_equals($row->browser_hash, $this->browserHash($request)) ? $row : null;
    }

    public function forget(Request $request, string $purpose): void
    {
        $request->session()->forget(self::SESSION.'.'.$purpose);
    }

    public function secondsUntilResend(VerificationCode $row): int
    {
        if (! $row->last_sent_at) {
            return 0;
        }

        $pause = min(60 * 2 ** max(0, $row->sends - 1), 600);
        $next = $row->last_sent_at->copy()->addSeconds($pause);

        return max(0, (int) ceil(now()->diffInSeconds($next, false)));
    }

    /** `ma***@gm***.com`, `+7 *** ***-45-67` — enough to recognise, not to learn. */
    public static function mask(string $kind, string $address): string
    {
        if ($kind === 'email') {
            [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');
            $dot = strrpos($domain, '.');
            $host = $dot === false ? $domain : substr($domain, 0, $dot);
            $tld = $dot === false ? '' : substr($domain, $dot);

            return mb_substr($local, 0, 2).'***@'.mb_substr($host, 0, 2).'***'.$tld;
        }

        $digits = preg_replace('/\D/', '', $address);

        return '+'.substr($digits, 0, max(1, strlen($digits) - 10)).' *** ***-'.substr($digits, -4, 2).'-'.substr($digits, -2);
    }

    public static function normalise(string $kind, string $address): string
    {
        $address = trim($address);

        if ($kind === 'email') {
            return Str::lower($address);
        }

        return \App\Core\Support\Phones::parse($address)['e164'] ?? '+'.preg_replace('/\D/', '', $address);
    }

    /** Rows older than a month leave; the log is about what is happening now. */
    public static function prune(): int
    {
        return VerificationCode::where('created_at', '<', now()->subDays(30))->delete();
    }

    protected function deliver(Request $request, VerificationCode $row, Channel $channel): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $row->code_hash = $this->hash($row, $code);
        $row->sends++;
        $row->last_sent_at = now();

        $result = $channel->send($row->kind, $row->address, [
            'code' => $code,
            // The whole message, for a channel that carries text (an SMS):
            // written here, in the reader's language, so no channel composes
            // its own sentence and no two channels word it differently.
            'text' => self::text($code),
            'purpose' => $row->purpose,
            'link' => $this->link($row, $code),
            'locale' => app()->getLocale(),
            'user' => $row->user,
        ]);

        $row->status = $result->ok() ? 'sent' : $result->status;
        $row->error = $result->error ? Str::limit($result->error, 290) : null;
        $row->save();

        RateLimiter::hit($this->key('addr', $row->kind.':'.$row->address), 3600);
        RateLimiter::hit($this->key('ip', ClientAddress::subject($request->ip())), 3600);

        hook_action('core.verification.sent', ['code' => $row, 'result' => $result]);
    }

    protected function limited(Request $request, string $kind, string $address): ?string
    {
        if (RateLimiter::tooManyAttempts($this->key('addr', $kind.':'.$address), self::PER_ADDRESS_HOURLY)
            || RateLimiter::tooManyAttempts($this->key('ip', ClientAddress::subject($request->ip())), self::PER_IP_HOURLY)) {
            return __('Too many codes were asked for. Try again in an hour.');
        }

        return null;
    }

    /**
     * The address in the letter's button: the row and the code, nothing else.
     * Not a signed route — every storefront address exists once per locale,
     * and a signature over one of them breaks in the other (see OrderKey).
     */
    protected function link(VerificationCode $row, string $code): ?string
    {
        return \Illuminate\Support\Facades\Route::has('account.code.link')
            ? route('account.code.link', ['code' => $row->id, 'secret' => $code])
            : null;
    }

    protected function hash(VerificationCode $row, string $code): string
    {
        return hash_hmac('sha256', $row->id.':'.$code, (string) config('app.key'));
    }

    protected function browserHash(Request $request): string
    {
        $token = $request->session()->get(self::SESSION.'.browser');

        if (! $token) {
            $token = Str::random(40);
            $request->session()->put(self::SESSION.'.browser', $token);
        }

        return hash('sha256', $token);
    }

    protected function key(string $kind, string $value): string
    {
        return 'verification:'.$kind.':'.sha1($value);
    }
}

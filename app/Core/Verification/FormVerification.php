<?php

namespace App\Core\Verification;

use App\Core\Support\Phones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * "Confirm the contact by a code" on a public form (PLAN §44.3) — what stops
 * orders, one-click purchases and callbacks with somebody else's number.
 *
 * A form that CAN ask is registered by its owner through
 * `core.verified_forms` (`key => ['label' => …, 'kinds' => ['phone',
 * 'email']]`, the kinds in the order the form prefers them); the shop ticks
 * which DO ask (`core.verify_forms`). Which contact is asked is the first of
 * the form's kinds that some channel can reach — a shop without SMS asks the
 * email — and a form that can reach none asks nothing: failing open, because
 * refusing every order over a missing SMS gateway is worse than the spam it
 * stops, and the guard says so instead (`core.verification_reachable`).
 *
 * Asked of guests only (required()). Not asked twice: a contact proven in
 * this browser within 90 days (an encrypted cookie of hashes, never the
 * address itself). Never globally "proven" — a competitor who knows a real
 * customer's number would then place orders under it without a code.
 */
class FormVerification
{
    public const COOKIE = 'torgash_verified';

    public const PURPOSE = 'form_contact';

    protected const DAYS = 90;

    public function __construct(protected Channels $channels) {}

    /** @return array<string, array{label: string, kinds: list<string>}> */
    public static function forms(): array
    {
        $forms = [];

        foreach ((array) hook_filter('core.verified_forms', []) as $key => $form) {
            $forms[(string) $key] = [
                'label' => label_text($form['label'] ?? $key, null, (string) $key),
                'kinds' => array_values(array_intersect((array) ($form['kinds'] ?? []), ['phone', 'email'])),
                // Confirmed whatever the shop ticks — not offered as a choice.
                'always' => (bool) ($form['always'] ?? false),
            ];
        }

        return $forms;
    }

    /**
     * Asked of GUESTS only. A signed-in customer already answers "who is
     * this": the order lands in their history and the account is the barrier
     * — which the shop can raise by confirming the email when an account is
     * made (the "Creating an account" form).
     */
    public function required(string $form): bool
    {
        return ! auth()->check()
            && (in_array($form, (array) setting('core', 'verify_forms', []), true) || (self::forms()[$form]['always'] ?? false))
            && $this->kindFor($form) !== null;
    }

    /** The contact this form asks to confirm, or null when none can be reached. */
    public function kindFor(string $form): ?string
    {
        foreach (self::forms()[$form]['kinds'] ?? [] as $kind) {
            if ($this->channels->offers($kind)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Why this submission may not go yet, or null. `$contacts` is what the
     * form carries: `['phone' => …, 'email' => …]`.
     *
     * @param  array<string, ?string>  $contacts
     */
    /**
     * Which of the form's contacts to confirm: the first one the person
     * actually TYPED that something can reach — a question sent with only an
     * email is confirmed by email even where SMS exists — else the form's
     * preferred kind, with no address yet.
     *
     * @param  array<string, ?string>  $contacts
     * @return array{0: ?string, 1: ?string} kind, normalised address
     */
    public function pick(string $form, array $contacts, ?string $country = null): array
    {
        foreach (self::forms()[$form]['kinds'] ?? [] as $kind) {
            $address = $this->normalise($kind, (string) ($contacts[$kind] ?? ''), $country);

            if ($address !== null && $this->channels->offers($kind)) {
                return [$kind, $address];
            }
        }

        return [$this->kindFor($form), null];
    }

    public function refusal(Request $request, string $form, array $contacts, ?string $country = null): ?string
    {
        if (! $this->required($form)) {
            return null;
        }

        [$kind, $address] = $this->pick($form, $contacts, $country);

        if ($address === null) {
            return $kind === 'phone'
                ? __('Type your phone — the shop confirms it by a code.')
                : __('Type your email — the shop confirms it by a code.');
        }

        return $this->verified($request, $kind, $address) ? null : ($kind === 'phone'
            ? __('Confirm the phone: press "Get a code" and type the code we send.')
            : __('Confirm the email: press "Get a code" and type the code we send.'));
    }

    public function verified(Request $request, string $kind, string $address): bool
    {
        $user = $request->user();

        if ($user && (
            ($kind === 'email' && $user->email_verified_at && strcasecmp((string) $user->email, $address) === 0)
            || ($kind === 'phone' && $user->phone_verified_at && $user->phone === $address)
        )) {
            return true;
        }

        $hashes = $this->remembered($request);

        return isset($hashes[$this->hash($kind, $address)]);
    }

    /** Remembers a proven contact in this browser. */
    public function remember(Request $request, string $kind, string $address): void
    {
        $hashes = $this->remembered($request);
        $hashes[$this->hash($kind, $address)] = now()->addDays(self::DAYS)->timestamp;

        // Newest last; a browser never carries more than a handful.
        $hashes = array_slice($hashes, -10, null, true);
        $request->cookies->set(self::COOKIE, json_encode($hashes));
        Cookie::queue(self::COOKIE, json_encode($hashes), self::DAYS * 24 * 60);
    }

    /**
     * `$country` is the country the FORM names, when it has one (the
     * checkout's delivery country) — a number typed there without its code is
     * that country's before it is the visitor's town's.
     */
    public function normalise(?string $kind, string $address, ?string $country = null): ?string
    {
        $address = trim($address);

        if ($address === '' || $kind === null) {
            return null;
        }

        if ($kind === 'email') {
            return filter_var($address, FILTER_VALIDATE_EMAIL) ? mb_strtolower($address) : null;
        }

        $country = preg_match('/^[A-Za-z]{2}$/', (string) $country) ? $country : null;

        return Phones::parse($address, $country)['e164'] ?? null;
    }

    /** @return array<string, int> hash => until */
    protected function remembered(Request $request): array
    {
        $raw = json_decode((string) $request->cookie(self::COOKIE, ''), true);

        return array_filter(is_array($raw) ? $raw : [], fn ($until) => (int) $until > now()->timestamp);
    }

    protected function hash(string $kind, string $address): string
    {
        return substr(hash_hmac('sha256', $kind.':'.$address, (string) config('app.key')), 0, 24);
    }
}

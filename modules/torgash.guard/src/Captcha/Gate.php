<?php

namespace Torgash\Guard\Captcha;

use Illuminate\Http\Request;

/**
 * Who gets asked, and on which form.
 *
 * The provider knows how to ask the question; this knows whether it should be
 * asked at all — and the answer is deliberately not "always", because a CAPTCHA
 * is a tax on every real customer. It is the last cheap layer under core's
 * honeypot, form-age stamp and rate limits, switched on for the ONE form that
 * is being spammed rather than for the shop.
 *
 * Three exemptions, in order of how obvious they are:
 *
 * 1. **Staff.** Somebody who is signed into the admin has already proved more
 *    about themselves than a picture of a sum ever could.
 * 2. **Trusted customer groups**, which the shop picks. A wholesale buyer
 *    placing their fifth order this week should not be doing arithmetic.
 * 3. **Everybody, when the provider is not actually usable** — no GD, no site
 *    key. That one is not generosity: a guard that cannot run must fail OPEN
 *    and say so on its settings screen, because failing closed would take the
 *    shop's contact form off the air for a reason nobody could see.
 */
class Gate
{
    /** Session key of the right answers kept for a form, `form => until` (a timestamp). */
    protected const KEPT = 'guard.captcha_kept';

    public function __construct(protected Captchas $captchas) {}

    /** The provider to use for this form and this visitor, or null for none. */
    public function providerFor(string $form): ?Provider
    {
        if (! $this->coversForm($form) || $this->isExempt() || $this->answered($form)) {
            return null;
        }

        $provider = $this->captchas->current();

        return $provider && $provider->unavailable() === null ? $provider : null;
    }

    /** The widget for a form, or '' — what `core.form.fields` renders. */
    public function widget(string $form): string
    {
        return $this->providerFor($form)?->render($form) ?? '';
    }

    /**
     * The answer for `core.form.check`: null passes, a string refuses with that
     * message.
     *
     * A visitor exempt at render time is exempt here too, which is what makes
     * the two halves agree — a customer who was never shown the box must not be
     * refused for leaving it empty.
     */
    public function check(Request $request, string $form, bool $keep = false): ?string
    {
        $provider = $this->providerFor($form);

        if (! $provider) {
            return null;
        }

        if (! $provider->verify($request, $form)) {
            return __('That is not the right answer to the sum. Please try the new one.');
        }

        // An answer works once — but on a form that asks to KEEP it (the
        // checkout, FormGuard::checkAdded()) "once" is one ORDER, not one
        // press of the button: a submission refused for a mistyped street
        // does not cost a second sum. Kept no longer than an answer lives,
        // and spent the moment the form goes through (spend()).
        if ($keep) {
            $request->session()->put(self::KEPT.'.'.$form, now()->addMinutes($this->ttl())->getTimestamp());
        }

        return null;
    }

    /** The form went through: a kept answer is used up. */
    public function spend(Request $request, string $form): void
    {
        $request->session()->forget(self::KEPT.'.'.$form);
    }

    /** Whether this visitor's right answer on the form is still kept. */
    protected function answered(string $form): bool
    {
        return (int) session(self::KEPT.'.'.$form, 0) > now()->getTimestamp();
    }

    /** How long an answer lives, in minutes — the shop's setting. */
    protected function ttl(): int
    {
        return max(1, (int) setting('torgash.guard', 'captcha_ttl', 10));
    }

    protected function coversForm(string $form): bool
    {
        return in_array($form, (array) setting('torgash.guard', 'captcha_forms', []), true);
    }

    protected function isExempt(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Staff — any role at all, which is what this project means by staff
        // (never "has the admin role").
        if ($user->roles()->exists()) {
            return true;
        }

        $exempt = array_map('strval', (array) setting('torgash.guard', 'captcha_exempt_groups', []));

        return $exempt !== [] && in_array((string) $user->customer_group_id, $exempt, true);
    }
}

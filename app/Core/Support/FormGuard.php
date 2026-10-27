<?php

namespace App\Core\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * What stands between a public POST that writes rows and the bots that will
 * find it within a week.
 *
 * This is not a side feature. A public form is the single most reliably abused
 * thing a shop puts on the internet, and a module whose inbox fills with three
 * hundred casino adverts a day gets switched off — which is the same outcome as
 * never shipping it. So the defences are here, on every submission, rather than
 * as a setting somebody remembers to turn on.
 *
 * In CORE because the second such form arrived within the hour: an enquiry
 * writes a row, and buying in one click writes an ORDER, which is worse. Two
 * copies of these rules would have drifted, and the shop would have had two
 * different answers to "how fast is too fast".
 *
 * Three cheap layers, none of which asks the customer to prove anything:
 *
 * 1. A honeypot field, hidden from people and irresistible to anything filling
 *    in every input it can see.
 * 2. The form's own age, carried in an encrypted stamp. A form submitted a
 *    second after it was rendered was not typed into. The stamp is encrypted
 *    rather than plain so the number cannot simply be rewritten, and it also
 *    expires: a page left open overnight is stale, and a bot replaying one
 *    captured stamp for a week is doing exactly what the ceiling stops.
 * 3. A rate limit per IP and per identity (phone or email), so the one caller
 *    who does get through gets through once.
 *
 * A CAPTCHA is deliberately not here. It is a tax on every real customer to
 * stop a problem the three cheap layers stop most of, and the shops that need
 * one need a specific one — so the `core.form.check` hook lets a module add
 * it without this module knowing which.
 */
class FormGuard
{
    /**
     * The guarded forms a shop actually has, `name => label`.
     *
     * The names existed before this did — they are what `check()` counts rate
     * limits under — but only as constants scattered across three modules, so
     * nothing could ENUMERATE them. A screen offering "put a CAPTCHA on this
     * form and not that one" needs the list, and the list is core's question
     * (the guard names them, the check counts them) rather than any one
     * module's.
     *
     * Answered by whoever owns the form, the same shape as every other
     * registry here — so a module that adds a public form adds it to this
     * screen by saying so, and takes it away again when uninstalled.
     *
     * @return array<string, string>
     */
    public static function forms(): array
    {
        $forms = [];

        foreach (hook_filter('core.forms', []) as $name => $label) {
            $forms[(string) $name] = label_text($label, null, (string) $name);
        }

        return $forms;
    }

    /**
     * The honeypot's field name. Not "website" or "url": browsers autofill
     * those and would fail real people. This one means nothing to anybody.
     */
    public const TRAP = 'reference_note';

    /** The encrypted "when this form was rendered" stamp. */
    public const STAMP = 'form_stamp';

    /** A form filled in faster than this was not filled in by a person. */
    protected const MIN_SECONDS = 3;

    /** ...and one older than this is a replay, or a tab from yesterday. */
    protected const MAX_SECONDS = 86400;

    /** Submissions per IP, and per phone/email, within the decay window. */
    protected const PER_IP = 5;

    protected const PER_IDENTITY = 3;

    protected const DECAY_SECONDS = 600;

    /** A stamp for a form being rendered right now. */
    public function stamp(): string
    {
        return Crypt::encryptString((string) time());
    }

    /**
     * Why this submission is being refused, or null to let it through.
     *
     * Returns a REASON rather than throwing, because the caller answers a
     * honeypot hit differently from a rate limit: the bot is told everything
     * went fine, and the human is told to wait.
     *
     * @return array{reason: string, message: string}|null
     */
    public function check(Request $request, ?string $identity, string $form = ''): ?array
    {
        return $this->announced($request, $form, $identity, $this->refusal($request, $identity, $form));
    }

    /**
     * Only what modules add to a form (`core.form.check` — a CAPTCHA), none
     * of the three cheap layers. For the checkout, which cannot take them: a
     * theme may draw its page without the stamp (every order refused) or a
     * customer's autofill may land in the trap (an order silently dropped),
     * and a fourth order in ten minutes is a good day, not abuse. Nothing is
     * asked unless the shop ticked the form on a module's screen.
     *
     * The module is told to KEEP a right answer across a submission refused
     * for something else — a mistyped street must not cost a second sum —
     * until `accepted()` says the order went through.
     *
     * @return array{reason: string, message: string}|null
     */
    public function checkAdded(Request $request, string $form): ?array
    {
        return $this->announced($request, $form, null, $this->added($request, $form, keep: true));
    }

    /** The form went through: whatever a module kept for it (`checkAdded()`) is spent. */
    public function accepted(Request $request, string $form): void
    {
        hook_action('core.form.accepted', ['request' => $request, 'form' => $form]);
    }

    /**
     * @param  array{reason: string, message: string}|null  $refusal
     * @return array{reason: string, message: string}|null
     */
    protected function announced(Request $request, string $form, ?string $identity, ?array $refusal): ?array
    {
        if ($refusal !== null) {
            // Said out loud, because a refusal is evidence and this is the only
            // place that has it. Core does nothing with it — a module that
            // counts what an address is doing (the guard's blocking, PLAN
            // §25.2/4) listens, and a shop without one fires into nothing.
            hook_action('core.form.refused', [
                'request' => $request,
                'form' => $form,
                'reason' => $refusal['reason'],
                'identity' => $identity,
            ]);
        }

        return $refusal;
    }

    /** @return array{reason: string, message: string}|null */
    protected function refusal(Request $request, ?string $identity, string $form): ?array
    {
        if (trim((string) $request->input(self::TRAP, '')) !== '') {
            // Nothing is written and nothing is said. A bot that learns which
            // of its submissions failed is a bot that stops filling the trap.
            return ['reason' => 'trap', 'message' => __('Thank you — we will be in touch.')];
        }

        if (! $this->stampIsPlausible((string) $request->input(self::STAMP, ''))) {
            return ['reason' => 'stamp', 'message' => __('The form was open too long. Please send it again.')];
        }

        // A client, not an address: an IPv6 /64 is one counter (ClientAddress).
        $ip = ClientAddress::subject($request->ip());

        if (RateLimiter::tooManyAttempts($this->key('ip', $form, $ip), self::PER_IP)) {
            return ['reason' => 'rate', 'message' => __('Too many enquiries from here. Please try again a little later.')];
        }

        if ($identity && RateLimiter::tooManyAttempts($this->key('id', $form, $identity), self::PER_IDENTITY)) {
            return ['reason' => 'rate', 'message' => __('We already have your enquiry — we will be in touch.')];
        }

        return $this->added($request, $form);
    }

    /**
     * Whatever else a shop has installed gets the last word: a captcha, a
     * blocklist, a fraud service. Answering with a string refuses. The form
     * is named so one listener can guard the callback and let the checkout
     * alone, or the other way round.
     *
     * @return array{reason: string, message: string}|null
     */
    protected function added(Request $request, string $form, bool $keep = false): ?array
    {
        $extra = hook_filter('core.form.check', null, ['request' => $request, 'form' => $form, 'keep' => $keep]);

        return is_string($extra) && $extra !== '' ? ['reason' => 'module', 'message' => $extra] : null;
    }

    /** Counted only once the submission is actually accepted. */
    public function record(Request $request, ?string $identity, string $form = ''): void
    {
        RateLimiter::hit($this->key('ip', $form, ClientAddress::subject($request->ip())), self::DECAY_SECONDS);

        if ($identity) {
            RateLimiter::hit($this->key('id', $form, $identity), self::DECAY_SECONDS);
        }
    }

    /**
     * Counted PER FORM: somebody who asked a question and then decided to buy
     * has not done the same thing twice, and one shared counter would turn a
     * good customer into a blocked one.
     */
    public function key(string $kind, string $form, string $value): string
    {
        return 'form:'.$form.':'.$kind.':'.$value;
    }

    protected function stampIsPlausible(string $stamp): bool
    {
        if ($stamp === '') {
            return false;
        }

        try {
            $age = time() - (int) Crypt::decryptString($stamp);
        } catch (Throwable) {
            // Not ours, or tampered with. Either way it did not come from a
            // form this shop rendered.
            return false;
        }

        return $age >= self::MIN_SECONDS && $age <= self::MAX_SECONDS;
    }
}

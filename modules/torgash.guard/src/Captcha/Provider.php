<?php

namespace Torgash\Guard\Captcha;

use Illuminate\Http\Request;

/**
 * The vendor-specific half of "prove you are a person".
 *
 * The shape exists so the built-in one is not privileged: reCAPTCHA, hCaptcha
 * and Turnstile are three methods each, in a module of their own, and this one
 * ships in the box only because a shop should not have to sign up for an
 * account somewhere to stop a spammer.
 *
 * Everything AROUND the provider stays here — which forms are protected, who is
 * exempt, where the widget is drawn, what a failure says — for the same reason
 * `torgash.payments` owns the route, the dedup and the log while a gateway only
 * talks to its bank.
 */
interface Provider
{
    /** Stable key, stored in the setting. */
    public static function key(): string;

    /** English source label for the picker. */
    public static function label(): string;

    /**
     * What is wrong with this provider here and now, in the shop owner's words
     * — a missing site key, an extension PHP was built without.
     *
     * Returned rather than thrown because nothing has failed yet: this is the
     * settings screen warning somebody while they can still fix it. And it is
     * why an unconfigured CAPTCHA does NOT silently pass everybody: a guard
     * that is off must say so on the screen where it was switched on.
     */
    public function unavailable(): ?string;

    /**
     * The widget, as HTML, for one form.
     *
     * The form name is passed because a page can carry two guarded forms at
     * once and each needs its own challenge — a callback dialog in the header
     * and a comment box further down are not the same question.
     */
    public function render(string $form): string;

    /** Did they get it right? Never throws: a broken provider must not 500 a form. */
    public function verify(Request $request, string $form): bool;
}

<?php

namespace Torgash\Guard\Captcha;

/**
 * Who can ask "are you a person here". A provider module answers
 * `guard.captcha.providers` with `['its_key' => ItsClass::class]` and needs
 * nothing else from us — the same registry shape as payment gateways and
 * exchange-rate sources.
 *
 * The built-in arithmetic one is registered by this module the same way, so it
 * is not privileged: a Turnstile module replaces it by being chosen in the
 * setting, not by patching anything.
 */
class Captchas
{
    /** @return array<string, class-string<Provider>> */
    public function all(): array
    {
        return hook_filter('guard.captcha.providers', []);
    }

    public function find(?string $key): ?Provider
    {
        $class = $this->all()[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** The one the shop chose, or null when it chose none. */
    public function current(): ?Provider
    {
        return $this->find((string) setting('torgash.guard', 'captcha_provider', ''));
    }

    /** @return array<string, string> key => translated label, for the picker */
    public function options(): array
    {
        $options = ['' => __('None')];

        foreach ($this->all() as $key => $class) {
            $options[$key] = __($class::label());
        }

        return $options;
    }
}

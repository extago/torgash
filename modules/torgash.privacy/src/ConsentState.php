<?php

namespace Torgash\Privacy;

use App\Core\Privacy\Consent;

/**
 * What THIS visitor has allowed (PLAN §49), read from their choice cookie and
 * the shop's mode — the answer to core's `core.consent.allows`.
 *
 * Per request (registered scoped and forgotten with the request): it is about
 * one visitor, and a persistent worker serving the next one with this one's
 * "yes" would be the exact failure this module exists to prevent.
 *
 * The cookie holds a version and the categories allowed — `1:statistics,
 * marketing`, or `1:` for "only the necessary". A choice made under an older
 * version is no choice at all, which is how the shop asks everybody again
 * after changing what it runs.
 */
class ConsentState
{
    public const COOKIE = 'torgash_consent';

    public const VERSION = '1';

    /** @var list<string>|null|false  false = not read yet, null = no choice */
    protected array|null|false $choice = false;

    public function mode(): string
    {
        return (string) setting('torgash.privacy', 'consent_mode', 'opt_in');
    }

    /** @return list<string>|null the categories chosen, or null when nothing was chosen */
    public function choice(): ?array
    {
        if ($this->choice === false) {
            $this->choice = self::parse((string) request()->cookie(self::COOKIE, ''));
        }

        return $this->choice;
    }

    public function decided(): bool
    {
        return $this->choice() !== null;
    }

    public function allows(string $category): bool
    {
        if ($category === Consent::NECESSARY) {
            return true;
        }

        $choice = $this->choice();

        return match ($this->mode()) {
            'off' => true,
            'opt_out' => $choice !== null
                ? in_array($category, $choice, true)
                // "Do not sell or share", said by the browser itself.
                : ! ($category === 'marketing' && $this->gpc()),
            default => $choice !== null && in_array($category, $choice, true),
        };
    }

    /** @return list<string> every non-necessary category this visitor may run */
    public function allowed(): array
    {
        return array_values(array_filter(
            array_keys(Consent::CATEGORIES),
            fn (string $category) => $category !== Consent::NECESSARY && $this->allows($category),
        ));
    }

    /** Should the banner ask? Only where asking first is the rule, and only once. */
    public function shouldAsk(): bool
    {
        return $this->mode() === 'opt_in' && ! $this->decided();
    }

    public function gpc(): bool
    {
        return setting('torgash.privacy', 'respect_gpc', true) && request()->header('Sec-GPC') === '1';
    }

    /** @param  list<string>  $categories */
    public static function encode(array $categories): string
    {
        return self::VERSION.':'.implode(',', $categories);
    }

    /** @return list<string>|null */
    public static function parse(string $value): ?array
    {
        if (! str_starts_with($value, self::VERSION.':')) {
            return null;
        }

        $list = array_filter(explode(',', substr($value, strlen(self::VERSION) + 1)));

        return array_values(array_filter($list, fn ($c) => Consent::exists($c) && $c !== Consent::NECESSARY));
    }

    /** Forget what was read — a choice was just made in this request. */
    public function remember(array $categories): void
    {
        $this->choice = $categories;
    }
}

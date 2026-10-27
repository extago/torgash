<?php

namespace App\Core\Privacy;

/**
 * What a visitor may have agreed to, as a vocabulary core owns (PLAN §49).
 *
 * Core, because two modules need the same words and neither may name the
 * other: `torgash.snippets` tags each piece of third-party code with a
 * category, and whatever privacy module is installed decides whether this
 * visitor allowed it. With no such module nothing answers `core.consent.allows`
 * and everything is allowed — exactly the shop as it was before.
 *
 * `necessary` is never asked about: it is the shop working (a session, a
 * basket), which needs nobody's permission.
 */
class Consent
{
    public const NECESSARY = 'necessary';

    /** category => declared English label (lang files translate it) */
    public const CATEGORIES = [
        'necessary' => 'Necessary',
        'functional' => 'Functional',
        'statistics' => 'Statistics',
        'marketing' => 'Marketing',
    ];

    /** What each category is for, in a sentence — for the banner and the admin. */
    public const DESCRIPTIONS = [
        'necessary' => 'What the shop cannot work without: signing in, the basket, your choices. Always on.',
        'functional' => 'Extras from other services: a support chat, maps, reviews widgets.',
        'statistics' => 'Counting visits to learn what works — Google Analytics, Yandex Metrica and the like.',
        'marketing' => 'Advertising pixels that remember the visit to show ads elsewhere — Facebook, TikTok, Google Ads.',
    ];

    public static function exists(string $category): bool
    {
        return isset(self::CATEGORIES[$category]);
    }

    /** May code of this category run for the current visitor? */
    public static function allows(string $category): bool
    {
        if ($category === self::NECESSARY || ! self::exists($category)) {
            return true;
        }

        return (bool) hook_filter('core.consent.allows', true, ['category' => $category]);
    }

    /**
     * Somebody agreed to something — a form's "I agree" box, a choice in a
     * cookie banner. Announced, not stored: keeping the record is a privacy
     * module's job, and a shop without one has no record to keep.
     *
     * @param  array{kind: string, text?: string, categories?: list<string>, user_id?: int|null, email?: string|null, phone?: string|null}  $context
     */
    public static function given(array $context): void
    {
        hook_action('core.consent.given', $context);
    }
}

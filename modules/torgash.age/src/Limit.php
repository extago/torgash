<?php

namespace Torgash\Age;

/**
 * What the age rules on one thing ask, folded into one value: the OLDEST age
 * each kind of rule sets — `show` (as usual, asked only with the order),
 * `cover` (photos covered) and `hide` (not in lists) — 0 where none does.
 *
 * Kept per kind rather than as "the oldest age and the strictest showing",
 * because those two can come from different rules: a category "16, hide"
 * with a product "21, cover" in it is covered for an 18-year-old, not hidden
 * — the 16 rule no longer applies to them, the 21 one still does. A rule
 * applies to a visitor while its age is above what they said they are; of
 * those that apply, the strictest showing wins (`treatment()`).
 */
final class Limit
{
    public const SHOW = 'show';

    public const COVER = 'cover';

    public const HIDE = 'hide';

    public const KINDS = [self::SHOW, self::COVER, self::HIDE];

    private function __construct(
        public readonly int $show,
        public readonly int $cover,
        public readonly int $hide,
    ) {}

    /** One rule; null for no age (0 or less). */
    public static function of(int $age, string $display): ?self
    {
        if ($age <= 0) {
            return null;
        }

        return match ($display) {
            self::HIDE => new self(0, 0, $age),
            self::COVER => new self(0, $age, 0),
            default => new self($age, 0, 0),
        };
    }

    /** Both at once: the oldest age of each kind. */
    public static function merge(?self $a, ?self $b): ?self
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return new self(max($a->show, $b->show), max($a->cover, $b->cover), max($a->hide, $b->hide));
    }

    /** The oldest age any rule here sets — what the order and the window ask for. */
    public function age(): int
    {
        return max($this->show, $this->cover, $this->hide);
    }

    /** The age that shows everything there is as usual: covered or hidden until then. */
    public function liftAge(): int
    {
        return max($this->cover, $this->hide);
    }

    /** What a visitor who has said they are $confirmed is shown. */
    public function treatment(int $confirmed): string
    {
        return match (true) {
            $this->hide > $confirmed => self::HIDE,
            $this->cover > $confirmed => self::COVER,
            default => self::SHOW,
        };
    }
}

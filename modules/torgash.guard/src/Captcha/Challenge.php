<?php

namespace Torgash\Guard\Captcha;

/**
 * The answer, kept where the visitor cannot read it.
 *
 * The whole idea of an image CAPTCHA is that the question is in pixels and the
 * answer is on the server, so this is the half that matters: the session holds
 * what the picture says, and the page holds only a nonce naming which picture.
 *
 * Three decisions:
 *
 * 1. **Keyed by form AND by a nonce.** Per form alone looks sufficient right up
 *    until a page carries two of them — a callback dialog in the header and a
 *    comment box below — where the second image to load would quietly overwrite
 *    the first one's answer and the customer would be told they typed it wrong.
 * 2. **One use.** `take()` reads and forgets, so a solved answer cannot be
 *    replayed. A bot that pays somebody a cent to solve one gets one
 *    submission for it, not a thousand.
 * 3. **A short life and a small cap.** Answers expire, and only the last few
 *    per form are kept — a script requesting ten thousand images must not be
 *    able to grow somebody's session file until the disk is full.
 */
class Challenge
{
    protected const KEY = 'guard.captcha';

    /** More than a person could have open at once; fewer than a script wants. */
    protected const KEEP = 5;

    public static function put(string $form, string $nonce, int $answer, int $ttlSeconds): void
    {
        $all = self::all();
        $bucket = $all[$form] ?? [];

        $bucket[$nonce] = ['answer' => $answer, 'expires' => now()->timestamp + $ttlSeconds];

        // Newest last, oldest dropped — expiry alone would not stop somebody
        // asking for images faster than they expire.
        $all[$form] = array_slice($bucket, -self::KEEP, null, true);

        session()->put(self::KEY, $all);
    }

    /**
     * The answer for this nonce, if it has not been used or expired — and gone
     * either way afterwards, which is what makes "regenerate on error" the
     * default rather than a feature somebody has to remember.
     */
    public static function take(string $form, string $nonce): ?int
    {
        $all = self::all();
        $entry = $all[$form][$nonce] ?? null;

        if ($entry === null) {
            return null;
        }

        unset($all[$form][$nonce]);
        session()->put(self::KEY, $all);

        return ($entry['expires'] ?? 0) >= now()->timestamp ? (int) $entry['answer'] : null;
    }

    /** @return array<string, array<string, array{answer: int, expires: int}>> */
    protected static function all(): array
    {
        $all = (array) session()->get(self::KEY, []);
        // now(), never time(): everything else here reasons about the clock
        // through Carbon, and a mix of the two is a rule that cannot be tested.
        $now = now()->timestamp;

        // Housekeeping on the way past: expired entries go, and a form left
        // with nothing goes with them.
        foreach ($all as $form => $bucket) {
            $all[$form] = array_filter(
                (array) $bucket,
                fn ($entry) => is_array($entry) && ($entry['expires'] ?? 0) >= $now,
            );

            if ($all[$form] === []) {
                unset($all[$form]);
            }
        }

        return $all;
    }
}

<?php

namespace Torgash\Guard\TwoFactor;

/**
 * RFC 6238, in one class and no dependency.
 *
 * The judgement call worth writing down: a library was the obvious answer and
 * is not the one taken, while for the QR picture it is (`Qr` requires
 * bacon/bacon-qr-code, because drawing a QR code by hand is a week's work with
 * a wrong answer in it). TOTP is the other case — it is `hash_hmac` over a
 * counter, a truncation the RFC specifies bit by bit, and base32 — some sixty
 * lines whose correctness is not a matter of opinion, because the RFC ships
 * test vectors. Those vectors are in the test suite. A dependency here would
 * buy nothing and would have to be trusted the same way.
 *
 * Fixed on purpose, with no settings behind them: six digits, thirty seconds,
 * SHA-1. Not because they are the strongest choices available but because they
 * are what Google Authenticator, Authy, 1Password, Bitwarden and every phone's
 * built-in code generator actually implement — a shop that changed them would
 * produce a QR code half its staff could not scan, and would find out one
 * manager at a time.
 */
class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /**
     * How far either side of now a code is still accepted, in steps.
     *
     * One, which is a minute wide in total. Phones drift, people start typing
     * at :29, and the alternative to tolerating that is a shop whose staff
     * believe the feature is broken. It is bounded on the other side by
     * `last_timestep`: a step that has already let somebody in never lets
     * anybody in again, so widening the window does not lengthen the life of
     * one code.
     */
    public const WINDOW = 1;

    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh secret: 160 bits, which is the length RFC 4226 asks for and what
     * every authenticator app expects to be handed.
     */
    public static function secret(): string
    {
        return static::base32Encode(random_bytes(20));
    }

    /**
     * The step number "now" falls in. The clock is the only shared state
     * between the shop and the phone, which is why a code from a handset an
     * hour out is refused and looks to its owner like a broken feature.
     *
     * Read through `now()` rather than `time()` so there is ONE clock in the
     * application — the same one every timestamp in the database is written
     * from, and one a test can move, which is how the thirty seconds between
     * enrolling and signing in are expressed without waiting for them.
     */
    public static function timestep(?int $at = null): int
    {
        return intdiv($at ?? now()->getTimestamp(), self::PERIOD);
    }

    /** The code for one step — six digits, zero-padded, as a string. */
    public static function code(string $secret, ?int $timestep = null): string
    {
        $timestep ??= static::timestep();

        // The counter as eight bytes, big-endian: `J` is not a pack() format on
        // 32-bit builds, so it is written out rather than assumed.
        $counter = pack('N2', ($timestep >> 32) & 0xFFFFFFFF, $timestep & 0xFFFFFFFF);

        $hash = hash_hmac('sha1', $counter, static::base32Decode($secret), true);

        // Dynamic truncation (RFC 4226 §5.3): the low nibble of the last byte
        // picks where in the digest to read four bytes from, and the top bit is
        // masked off so the result cannot be read as negative.
        $offset = ord($hash[19]) & 0x0F;
        $number = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3])
        ) % (10 ** self::DIGITS);

        return str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Does this code belong to this secret, and to a step nobody has used yet?
     *
     * Answers with the STEP it matched rather than with true, because the caller
     * has to store that number — that is what makes a code single-use. `$after`
     * is the last step already spent; anything at or below it is refused even
     * though the arithmetic is perfectly correct.
     */
    public static function verify(string $secret, string $code, ?int $after = null, ?int $at = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = static::timestep($at);

        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if ($after !== null && $step <= $after) {
                continue;
            }

            // Timing-safe, and the comparison is over two strings of the same
            // known length. A leak here would be a way to learn the code a
            // digit at a time, which for a six-digit secret is not academic.
            if (hash_equals(static::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The `otpauth://` address the QR code carries, and what a person types by
     * hand when they cannot scan it.
     *
     * The issuer appears twice — once as the label prefix, once as a parameter
     * — because the apps disagree about which one they read, and an entry
     * called "a@shop.test" with no shop name beside it is unfindable in a list
     * of twenty.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The secret in groups of four, which is how a human copies one by hand. */
    public static function readable(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // No `=` padding: every authenticator app accepts an unpadded secret
        // and a shop owner reading one aloud does not need trailing signs.
        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        // Whole bytes only: a base32 string whose length is not a multiple of
        // eight has a few bits of padding at the end and they are not data.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}

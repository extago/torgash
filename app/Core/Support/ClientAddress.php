<?php

namespace App\Core\Support;

/**
 * Who a request comes from, written the one way every counter is keyed by.
 *
 * An IPv4 address is itself. An IPv6 address is its /64 network
 * (`2001:db8:1:2::/64`): a provider hands one household, one phone or one
 * server a whole /64 — eighteen quintillion addresses — so a limit keyed by
 * the full address is a limit the client steps round by changing the last
 * group. An IPv4 address dressed as IPv6 (`::ffff:203.0.113.9`) is the IPv4
 * one. A subject passes through unchanged, so asking twice is safe; what is
 * not an address at all comes back as given, trimmed.
 *
 * Core's, because the limits are: the sign-in throttle, the public forms
 * (`FormGuard`) and the code sender (`Verifier`) all count per client, and
 * the guard module's blocking keys its rows the same way.
 */
class ClientAddress
{
    /** The part of an IPv6 address one client holds. */
    public const IPV6_PREFIX = 64;

    public static function subject(?string $ip): string
    {
        $ip = trim((string) $ip);
        $suffix = '/'.self::IPV6_PREFIX;
        $packed = @inet_pton(str_ends_with($ip, $suffix) ? substr($ip, 0, -strlen($suffix)) : $ip);

        if ($packed === false) {
            return $ip;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xFF\xFF")) {
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        return inet_ntop(substr($packed, 0, self::IPV6_PREFIX / 8).str_repeat("\0", 16 - self::IPV6_PREFIX / 8)).$suffix;
    }
}

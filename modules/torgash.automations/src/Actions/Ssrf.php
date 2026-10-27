<?php

namespace Torgash\Automations\Actions;

/**
 * The URL is written by an admin; the request is made by the server, from
 * inside the network. That is the shape of every SSRF, and a rule engine is a
 * particularly good one: it accepts a URL, calls it on a schedule, and reports
 * the response body back onto a screen.
 *
 * So a webhook may only leave the building. Refused: anything that is not
 * http(s), and any host that resolves to a loopback, link-local, private or
 * otherwise reserved address — 169.254.169.254 above all, which is the cloud
 * metadata endpoint and hands out credentials to whoever asks from inside.
 *
 * A development machine talking to a local receiver turns this off with the
 * module's `allow_private_hosts` setting, which is why the setting says out
 * loud that it must stay off in production.
 */
class Ssrf
{
    /** @return string|null the reason to refuse, or null when it may be called */
    public static function refuse(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return __('Refused: the URL must be http or https.');
        }

        if (setting('torgash.automations', 'allow_private_hosts')) {
            return null;
        }

        foreach (self::addresses($host) as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return __('Refused: :host resolves to a private or reserved address (:ip).', [
                    'host' => $host,
                    'ip' => $address,
                ]);
            }
        }

        return null;
    }

    /**
     * Every address the host answers with — all of them are checked, because a
     * name that resolves to one public and one private address is the classic
     * way around a check that only looks at the first.
     *
     * A name that resolves to nothing is left to the HTTP client to fail on:
     * refusing here would turn a temporary DNS problem into "your URL is
     * dangerous", which sends the admin looking in the wrong place.
     *
     * @return list<string>
     */
    protected static function addresses(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}

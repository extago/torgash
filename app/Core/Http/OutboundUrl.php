<?php

namespace App\Core\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A request to an address somebody TYPED — a picture by its link — that may
 * only reach the public internet.
 *
 * Without it the shop's server fetched whatever it was given: 127.0.0.1, the
 * database's port, the router, a cloud metadata address (audit 2026-10-15).
 * The host is resolved first and every address it resolves to must be public;
 * the request is then PINNED to the address that was checked (curl's
 * RESOLVE), so a DNS answer that changes between the check and the request
 * cannot slip a private one in. Redirects are followed by hand, each hop
 * checked the same way.
 *
 * Not for addresses the engine itself knows (a payment gateway, the update
 * server) or a URL the owner configured for an integration on the same
 * network on purpose (a webhook): those are not somebody pasting a link.
 */
class OutboundUrl
{
    public function __construct(protected HostResolver $resolver) {}

    /** @param  array<string, string>  $headers */
    public function get(string $url, int $timeout = 15, array $headers = [], int $redirects = 3): Response
    {
        for ($hop = 0; ; $hop++) {
            [$host, $port, $ip] = $this->check($url);

            $response = Http::timeout($timeout)->withHeaders($headers)->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]],
            ])->get($url);

            $location = $response->header('Location');
            if (! $response->redirect() || $location === '') {
                return $response;
            }
            if ($hop >= $redirects) {
                throw new RuntimeException(__('Too many redirects from :url.', ['url' => $url]));
            }

            $url = $this->absolute($location, $url);
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, port and the public address to use
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException(__('Only http:// and https:// links are supported.'));
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw new RuntimeException(__('The address :host could not be found.', ['host' => $host]));
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new RuntimeException(__('The link leads to an address inside a private network, which the shop does not fetch.'));
            }
        }

        return [$host, $port, $ips[0]];
    }

    /** An address on the public internet: not loopback, private, link-local, reserved or shared (CGNAT). */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 100.64.0.0/10 is the carriers' shared space — inside somebody's network, not the internet.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            return ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }

        return true;
    }

    protected function absolute(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = preg_replace('#/[^/]*$#', '/', (string) ($parts['path'] ?? '/'));

        return $origin.$dir.$location;
    }
}

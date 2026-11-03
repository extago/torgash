<?php

namespace App\Core\Backups;

use RuntimeException;

/**
 * A proxy a backup storage is reached through (PLAN §67.1): none, HTTP(S)
 * CONNECT or SOCKS5, with an optional login.
 *
 * Bucket and WebDAV go through curl, which speaks both itself (`curl()`).
 * SFTP is a socket phpseclib is handed already open, so the tunnel is opened
 * here (`open()`): a CONNECT request, or SOCKS5's greeting, login and
 * connect — a few dozen bytes each way. FTP is never offered one: ext-ftp
 * has no proxy, and passive mode opens a second connection anyway.
 */
class Proxy
{
    public const TYPES = ['none', 'http', 'socks5'];

    public function __construct(
        public readonly string $type,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username = '',
        public readonly string $password = '',
    ) {}

    /** @param  array<string, mixed>  $settings  @param  array<string, string>  $secrets */
    public static function from(array $settings, array $secrets): ?self
    {
        $type = (string) ($settings['proxy_type'] ?? 'none');

        if (! in_array($type, ['http', 'socks5'], true) || trim((string) ($settings['proxy_host'] ?? '')) === '') {
            return null;
        }

        return new self(
            $type,
            trim((string) $settings['proxy_host']),
            (int) ($settings['proxy_port'] ?? ($type === 'socks5' ? 1080 : 3128)),
            (string) ($settings['proxy_username'] ?? ''),
            (string) ($secrets['proxy_password'] ?? ''),
        );
    }

    /** The proxy as curl options (CURLOPT_*). */
    public function curl(): array
    {
        $options = [
            CURLOPT_PROXY => $this->host,
            CURLOPT_PROXYPORT => $this->port,
            // socks5h: the PROXY resolves the name, so a storage reachable
            // only from the proxy's side is reached.
            CURLOPT_PROXYTYPE => $this->type === 'socks5' ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_HTTP,
        ];

        if ($this->type === 'http') {
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
        }

        if ($this->username !== '') {
            $options[CURLOPT_PROXYUSERPWD] = $this->username.':'.$this->password;
        }

        return $options;
    }

    /** The proxy as Guzzle's `proxy` option (the S3 client). */
    public function guzzle(): string
    {
        $login = $this->username !== ''
            ? rawurlencode($this->username).':'.rawurlencode($this->password).'@'
            : '';

        return ($this->type === 'socks5' ? 'socks5h' : 'http').'://'.$login.$this->host.':'.$this->port;
    }

    /**
     * A socket to $host:$port through the proxy, ready for the protocol on
     * top (SSH).
     *
     * @return resource
     */
    public function open(string $host, int $port, int $timeout = 15)
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $timeout);

        if (! is_resource($socket)) {
            throw new RuntimeException("The proxy {$this->host}:{$this->port} cannot be reached: ".($error ?: 'error '.$errno));
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->type === 'socks5' ? $this->socks5($socket, $host, $port) : $this->connect($socket, $host, $port);
        } catch (RuntimeException $e) {
            fclose($socket);

            throw $e;
        }

        return $socket;
    }

    /** @param  resource  $socket */
    protected function connect($socket, string $host, int $port): void
    {
        $target = (str_contains($host, ':') ? "[$host]" : $host).':'.$port;
        $request = "CONNECT $target HTTP/1.1\r\nHost: $target\r\n";

        if ($this->username !== '') {
            $request .= 'Proxy-Authorization: Basic '.base64_encode($this->username.':'.$this->password)."\r\n";
        }

        fwrite($socket, $request."\r\n");

        $status = (string) fgets($socket);
        if (! preg_match('#^HTTP/\d(?:\.\d)?\s+2\d\d#', $status)) {
            throw new RuntimeException('The proxy refused the tunnel: '.trim($status ?: 'no answer'));
        }

        // The rest of the answer's head, up to the blank line.
        while (($line = fgets($socket)) !== false && trim($line) !== '') {
        }
    }

    /** RFC 1928 (and 1929 for the login). @param  resource  $socket */
    protected function socks5($socket, string $host, int $port): void
    {
        $login = $this->username !== '';
        fwrite($socket, $login ? "\x05\x02\x00\x02" : "\x05\x01\x00");

        $answer = $this->read($socket, 2);
        if ($answer[0] !== "\x05") {
            throw new RuntimeException('The proxy does not speak SOCKS5.');
        }

        if ($answer[1] === "\x02") {
            if (! $login) {
                throw new RuntimeException('The SOCKS5 proxy wants a login.');
            }

            fwrite($socket, "\x01".chr(strlen($this->username)).$this->username.chr(strlen($this->password)).$this->password);
            if ($this->read($socket, 2)[1] !== "\x00") {
                throw new RuntimeException('The SOCKS5 proxy did not take the login.');
            }
        } elseif ($answer[1] !== "\x00") {
            throw new RuntimeException('The SOCKS5 proxy accepts none of the ways to sign in offered.');
        }

        // CONNECT by name: the proxy resolves it.
        fwrite($socket, "\x05\x01\x00\x03".chr(strlen($host)).$host.pack('n', $port));

        $reply = $this->read($socket, 4);
        if ($reply[1] !== "\x00") {
            $why = [1 => 'general failure', 2 => 'not allowed by its rules', 3 => 'network unreachable', 4 => 'host unreachable', 5 => 'connection refused', 6 => 'TTL expired'][ord($reply[1])] ?? 'error '.ord($reply[1]);

            throw new RuntimeException("The SOCKS5 proxy could not reach $host:$port: $why.");
        }

        // The bound address that follows, by its kind.
        match ($reply[3]) {
            "\x01" => $this->read($socket, 4 + 2),
            "\x04" => $this->read($socket, 16 + 2),
            "\x03" => $this->read($socket, ord($this->read($socket, 1)) + 2),
            default => throw new RuntimeException('The SOCKS5 proxy answered in a way it should not.'),
        };
    }

    /** @param  resource  $socket */
    protected function read($socket, int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes) {
            $chunk = fread($socket, $bytes - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The proxy closed the connection.');
            }

            $data .= $chunk;
        }

        return $data;
    }
}

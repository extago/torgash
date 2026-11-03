<?php

// A proxy for the backup tests (BackupStoragesTest): HTTP CONNECT and SOCKS5
// on one port, told apart by the first byte, with a login when PROXY_USER is
// set. Every tunnel opened is written to PROXY_LOG as "kind host:port", so a
// test can tell the traffic went through it. Several tunnels at once (curl
// opens a new one when it likes).

$listener = stream_socket_server('tcp://127.0.0.1:'.getenv('PROXY_PORT'), $errno, $error);
if (! $listener) {
    fwrite(STDERR, "cannot listen: $error\n");
    exit(1);
}

$user = (string) getenv('PROXY_USER');
$password = (string) getenv('PROXY_PASSWORD');
$log = getenv('PROXY_LOG');

/** @var array<int, resource> $peer  socket id => the other end */
$peer = [];
$all = [];

function read_exactly($socket, int $bytes): string
{
    $data = '';
    while (strlen($data) < $bytes) {
        $chunk = fread($socket, $bytes - strlen($data));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('closed');
        }
        $data .= $chunk;
    }

    return $data;
}

function handshake($client, string $user, string $password, string $log)
{
    stream_set_blocking($client, true);
    stream_set_timeout($client, 10);
    $first = read_exactly($client, 1);

    if ($first === "\x05") {
        $methods = read_exactly($client, ord(read_exactly($client, 1)));
        if ($user !== '') {
            if (! str_contains($methods, "\x02")) {
                fwrite($client, "\x05\xFF");

                return null;
            }
            fwrite($client, "\x05\x02");
            read_exactly($client, 1);
            $u = read_exactly($client, ord(read_exactly($client, 1)));
            $p = read_exactly($client, ord(read_exactly($client, 1)));
            if ($u !== $user || $p !== $password) {
                fwrite($client, "\x01\x01");

                return null;
            }
            fwrite($client, "\x01\x00");
        } else {
            fwrite($client, "\x05\x00");
        }

        $head = read_exactly($client, 4);
        $host = match ($head[3]) {
            "\x01" => inet_ntop(read_exactly($client, 4)),
            "\x03" => read_exactly($client, ord(read_exactly($client, 1))),
            "\x04" => inet_ntop(read_exactly($client, 16)),
        };
        $port = unpack('n', read_exactly($client, 2))[1];
        $target = @stream_socket_client("tcp://$host:$port", $e, $m, 5);
        fwrite($client, $target ? "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00" : "\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00");
        file_put_contents($log, "socks5 $host:$port\n", FILE_APPEND);

        return $target ?: null;
    }

    $head = $first;
    while (! str_contains($head, "\r\n\r\n")) {
        $head .= read_exactly($client, 1);
    }
    if (! preg_match('#^CONNECT (\S+):(\d+) HTTP#', $head, $m)) {
        fwrite($client, "HTTP/1.1 405 Only CONNECT\r\n\r\n");

        return null;
    }
    if ($user !== '' && ! str_contains($head, 'Proxy-Authorization: Basic '.base64_encode("$user:$password"))) {
        fwrite($client, "HTTP/1.1 407 Proxy Authentication Required\r\nProxy-Authenticate: Basic realm=\"test\"\r\nContent-Length: 0\r\n\r\n");

        return null;
    }
    $host = trim($m[1], '[]');
    $target = @stream_socket_client("tcp://$host:{$m[2]}", $e, $msg, 5);
    fwrite($client, $target ? "HTTP/1.1 200 Connection established\r\n\r\n" : "HTTP/1.1 502 Bad Gateway\r\n\r\n");
    file_put_contents($log, "http $host:{$m[2]}\n", FILE_APPEND);

    return $target ?: null;
}

while (true) {
    $read = array_values($all);
    $read[] = $listener;
    $write = $except = null;

    if (@stream_select($read, $write, $except, 60) === false) {
        continue;
    }

    foreach ($read as $socket) {
        if ($socket === $listener) {
            $client = @stream_socket_accept($listener, 5);
            if (! $client) {
                continue;
            }
            try {
                $target = handshake($client, $user, $password, $log);
            } catch (Throwable) {
                $target = null;
            }
            if (! $target) {
                fclose($client);

                continue;
            }
            stream_set_blocking($client, false);
            stream_set_blocking($target, false);
            $all[(int) $client] = $client;
            $all[(int) $target] = $target;
            $peer[(int) $client] = $target;
            $peer[(int) $target] = $client;

            continue;
        }

        $other = $peer[(int) $socket] ?? null;
        $data = fread($socket, 65536);

        if ($data === '' || $data === false) {
            if (feof($socket)) {
                foreach ([$socket, $other] as $end) {
                    if ($end) {
                        unset($all[(int) $end], $peer[(int) $end]);
                        @fclose($end);
                    }
                }
            }

            continue;
        }

        // A blocking write of what was read: the tests move small files.
        stream_set_blocking($other, true);
        fwrite($other, $data);
        stream_set_blocking($other, false);
    }
}

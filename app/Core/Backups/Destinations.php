<?php

namespace App\Core\Backups;

use App\Core\Files\FileStorage;
use App\Models\BackupDestination;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\WebDAV\WebDAVAdapter;
use RuntimeException;
use Sabre\DAV\Client as DavClient;
use Sabre\HTTP\ClientHttpException;
use Throwable;

/**
 * The kinds of place a backup can be kept in (PLAN §67.1) and how each is
 * opened. Stage 1: one of the shop's own disks, a folder on the server, an
 * S3-compatible bucket; stage 2: SFTP, FTP(S), WebDAV, and a proxy for the
 * ones that can take one (SFTP, WebDAV, S3 — never FTP). OAuth drives come
 * as modules: a `Destination` registered on `core.backup.destinations`,
 * drawn by the same screen and opened the same way.
 */
class Destinations
{
    /** Core's own kinds. A module's are `extensions()`. */
    public const DRIVERS = ['disk', 'folder', 's3', 'sftp', 'ftp', 'webdav'];

    /** The core kinds that can be reached through a proxy. */
    public const PROXIED = ['s3', 'sftp', 'webdav'];

    /**
     * How many times a part of a file is sent to an S3 bucket before the
     * upload is given up on. The library's own 3 proved too few: Backblaze
     * answers "internal incident" for a while now and then, and a backup of
     * gigabytes was lost over two parts of five megabytes.
     */
    public const ATTEMPTS = 10;

    /** @return array<string, string> driver => English label, core's then the modules' */
    public function drivers(): array
    {
        return [
            'disk' => 'A disk of the shop',
            'folder' => 'A folder on this server',
            's3' => 'S3-compatible storage',
            'sftp' => 'SFTP server',
            'ftp' => 'FTP server',
            'webdav' => 'WebDAV server',
        ] + array_map(fn (Destination $kind) => $kind->label(), $this->extensions());
    }

    /**
     * The kinds modules add (`core.backup.destinations`), keyed by driver.
     * Asked each time, never kept: the listeners are registered at boot and
     * make their objects anew, so nothing of one request reaches the next.
     * A module's key that shadows a core kind, or a value that is not a
     * `Destination`, is dropped rather than let into the match below.
     *
     * @return array<string, Destination>
     */
    public function extensions(): array
    {
        $kinds = [];

        foreach ((array) hook_filter('core.backup.destinations', []) as $driver => $kind) {
            if ($kind instanceof Destination && is_string($driver) && ! in_array($driver, self::DRIVERS, true)
                && preg_match('/^[a-z][a-z0-9_]{1,40}$/', $driver)) {
                $kinds[$driver] = $kind;
            }
        }

        return $kinds;
    }

    /** @return list<string> every kind that can take the proxy fields */
    public function proxied(): array
    {
        return [...self::PROXIED, ...array_keys(array_filter($this->extensions(), fn (Destination $kind) => $kind->proxied()))];
    }

    public function open(BackupDestination $destination): Store
    {
        $proxy = in_array($destination->driver, self::PROXIED, true)
            ? Proxy::from((array) $destination->settings, $destination->secrets())
            : null;

        return match ($destination->driver) {
            'disk' => new Store(
                Storage::disk(in_array($destination->setting('disk'), FileStorage::DISKS, true) ? $destination->setting('disk') : 'local'),
                (string) $destination->setting('root', ''),
            ),
            'folder' => new Store(Storage::build([
                'driver' => 'local',
                'root' => (string) $destination->setting('path'),
                'throw' => true,
            ])),
            's3' => new Store(Storage::build([
                'driver' => 's3',
                'key' => $destination->secret('key'),
                'secret' => $destination->secret('secret'),
                'region' => (string) $destination->setting('region') ?: 'us-east-1',
                'bucket' => (string) $destination->setting('bucket'),
                'endpoint' => (string) $destination->setting('endpoint') ?: null,
                'use_path_style_endpoint' => (bool) $destination->setting('path_style', false),
                // A big file goes up in parts of five megabytes; a part the
                // service refuses with a server error is sent again after a
                // pause that grows (the SDK's "standard" mode), this many
                // times in all — the storage's own setting (`attempts`).
                'retries' => ['mode' => 'standard', 'max_attempts' => self::attempts($destination)],
                'http' => $proxy ? ['proxy' => $proxy->guzzle()] : [],
                'throw' => true,
            ]), (string) $destination->setting('root', '')),
            'sftp' => $this->sftp($destination, $proxy),
            'ftp' => new Store(Storage::build([
                'driver' => 'ftp',
                'host' => (string) $destination->setting('host'),
                'port' => (int) $destination->setting('port', 21),
                'username' => (string) $destination->setting('username'),
                'password' => $destination->secret('password'),
                // The folder the login starts in; the storage's own folder is
                // the Store's, made on the way — flysystem-ftp refuses a root
                // that does not exist yet, and the owner names one that doesn't.
                'root' => '',
                // Explicit TLS (AUTH TLS) — what "FTPS" means on almost every host.
                'ssl' => (bool) $destination->setting('ssl', true),
                'passive' => (bool) $destination->setting('passive', true),
                'timeout' => 60,
                'utf8' => true,
                'throw' => true,
            ]), (string) $destination->setting('root', '')),
            'webdav' => $this->webdav($destination, $proxy),
            default => ($this->extensions()[$destination->driver] ?? null)?->open($destination)
                ?? throw new RuntimeException('Unknown kind of backup storage: '.$destination->driver),
        };
    }

    /** The attempts an S3 storage makes for one part: its setting, or `ATTEMPTS`. */
    public static function attempts(BackupDestination $destination): int
    {
        return max(1, min(100, (int) ($destination->setting('attempts') ?: self::ATTEMPTS)));
    }

    /** The key an SFTP storage's server shows now (to confirm it). */
    public function hostKey(BackupDestination $destination): string
    {
        return $this->sftpConnection($destination, Proxy::from((array) $destination->settings, $destination->secrets()))->hostKey();
    }

    protected function sftpConnection(BackupDestination $destination, ?Proxy $proxy): SftpConnection
    {
        return new SftpConnection(
            (string) $destination->setting('host'),
            (int) $destination->setting('port', 22),
            (string) $destination->setting('username'),
            $destination->secret('password'),
            $destination->secret('private_key'),
            $destination->secret('passphrase'),
            (string) $destination->setting('fingerprint', ''),
            proxy: $proxy,
        );
    }

    protected function sftp(BackupDestination $destination, ?Proxy $proxy): Store
    {
        $connection = $this->sftpConnection($destination, $proxy);
        // Paths relative to the login's home, the folder made on the way.
        $adapter = new SftpAdapter($connection, '', disconnectOnDestruct: true);

        return new Store(new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]), (string) $destination->setting('root', ''));
    }

    protected function webdav(BackupDestination $destination, ?Proxy $proxy): Store
    {
        $client = new DavClient(array_filter([
            'baseUri' => rtrim((string) $destination->setting('url'), '/').'/',
            'userName' => (string) $destination->setting('username') ?: null,
            'password' => $destination->secret('password') ?: null,
            // Basic, sent with the first request: offered "any", curl asks
            // without a login first, is answered 401 and must send the body
            // again — which a backup streamed from a file cannot be.
            'authType' => DavClient::AUTH_BASIC,
        ]));

        foreach ($proxy?->curl() ?? [] as $option => $value) {
            $client->addCurlSetting($option, $value);
        }
        // A backup is hundreds of megabytes: no total time limit, but a
        // connection that says nothing for two minutes is given up.
        $client->addCurlSetting(CURLOPT_CONNECTTIMEOUT, 20);
        $client->addCurlSetting(CURLOPT_LOW_SPEED_LIMIT, 1);
        $client->addCurlSetting(CURLOPT_LOW_SPEED_TIME, 120);

        $adapter = new WebDAVAdapter($client, trim((string) $destination->setting('root', ''), '/'));

        return new Store(new FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => true]));
    }

    /**
     * Writes a small file, reads it back and deletes it. Null when all of that
     * worked, otherwise what went wrong. An SFTP server met for the first time
     * is not an error to put in words: `HostKeyNotConfirmed` is thrown on, so
     * the screen can show its key and ask.
     */
    public function test(BackupDestination $destination): ?string
    {
        $name = '.torgash-probe-'.Str::random(8).'.txt';
        $body = 'torgash '.now()->toIso8601String();

        try {
            $store = $this->open($destination);
            // A real file: curl (WebDAV) cannot upload from php://memory.
            $stream = tmpfile();
            fwrite($stream, $body);
            rewind($stream);
            $store->put($name, $stream);
            fclose($stream);

            $read = $store->read($name);
            $back = stream_get_contents($read);
            fclose($read);
            $store->delete($name);

            return $back === $body ? null : __('The file read back is not the one written.');
        } catch (Throwable $e) {
            // flysystem wraps it ("Unable to write file at …"): found in the chain.
            for ($cause = $e; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof HostKeyNotConfirmed) {
                    throw $cause;
                }
            }

            return Str::limit(self::reason($e), 500);
        }
    }

    /**
     * What went wrong, in the words of the place it went wrong: flysystem
     * wraps a server's "401 Unauthorized" in "Unable to check existence
     * for: .", so the first cause is added to the outermost message.
     */
    public static function reason(Throwable $e): string
    {
        $said = trim($e->getMessage()) ?: get_class($e);
        $root = $e;
        while ($root->getPrevious()) {
            $root = $root->getPrevious();
        }

        if ($root !== $e) {
            $cause = $root instanceof ClientHttpException
                ? $root->getHttpStatus().' '.$root->getMessage()
                : trim($root->getMessage());

            if ($cause !== '' && ! str_contains($said, $cause)) {
                $said .= ' — '.$cause;
            }
        }

        return $said;
    }

    /**
     * A form's fields for one driver, checked: [settings, secrets, errors].
     * A secret left blank keeps the stored one.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    public function fromInput(string $driver, array $input, ?BackupDestination $existing = null): array
    {
        $errors = [];
        $text = fn (string $key) => trim((string) ($input[$key] ?? ''));
        $old = $existing?->driver === $driver ? $existing->secrets() : [];

        switch ($driver) {
            case 'disk':
                $settings = [
                    'disk' => in_array($text('disk'), FileStorage::DISKS, true) ? $text('disk') : 'local',
                    'root' => $this->cleanFolder($text('root')) ?: 'backups',
                ];

                return [$settings, [], $errors];

            case 'folder':
                $path = rtrim($text('path'), '/\\');
                $error = $this->folderError($path);
                if ($error) {
                    $errors['path'] = $error;
                }

                return [['path' => $path], [], $errors];

            case 's3':
                $settings = [
                    'endpoint' => $text('endpoint'),
                    'region' => $text('region'),
                    'bucket' => $text('bucket'),
                    'root' => $this->cleanFolder($text('root')),
                    'path_style' => (bool) ($input['path_style'] ?? false),
                    'attempts' => max(1, min(100, (int) ($text('attempts') ?: self::ATTEMPTS))),
                ];
                $secrets = [
                    'key' => $text('key') !== '' ? $text('key') : (string) ($old['key'] ?? ''),
                    'secret' => $text('secret') !== '' ? $text('secret') : (string) ($old['secret'] ?? ''),
                ];

                if ($settings['bucket'] === '') {
                    $errors['bucket'] = __('Name the bucket.');
                }
                if ($settings['endpoint'] !== '' && ! filter_var($settings['endpoint'], FILTER_VALIDATE_URL)) {
                    $errors['endpoint'] = __('The address must be a full URL, https://…');
                }
                if ($secrets['key'] === '' || $secrets['secret'] === '') {
                    $errors['key'] = __('The access key and the secret are both needed.');
                }

                return $this->withProxy([$settings, $secrets, $errors], $input, $old);

            case 'sftp':
                $settings = [
                    'host' => $this->host($text('host')),
                    'port' => $this->port($input['port'] ?? null, 22),
                    'username' => $text('username'),
                    'root' => $this->cleanFolder($text('root')),
                    'auth' => $text('auth') === 'key' ? 'key' : 'password',
                    'fingerprint' => $text('fingerprint') !== '' ? SftpConnection::normalise($text('fingerprint')) : '',
                ];
                $keep = fn (string $key) => $text($key) !== '' ? (string) ($input[$key] ?? '') : (string) ($old[$key] ?? '');
                $secrets = $settings['auth'] === 'key'
                    ? ['private_key' => trim($keep('private_key')), 'passphrase' => $keep('passphrase'), 'password' => '']
                    : ['password' => $keep('password'), 'private_key' => '', 'passphrase' => ''];

                // Another server's address forgets the key confirmed for the
                // old one — unless a key was typed along with it.
                if ($existing?->driver === 'sftp' && $settings['host'] !== $existing->setting('host')
                    && $settings['fingerprint'] === (string) $existing->setting('fingerprint')) {
                    $settings['fingerprint'] = '';
                }

                $errors += $this->serverErrors($settings);
                if ($settings['auth'] === 'key' && $secrets['private_key'] === '') {
                    $errors['private_key'] = __('Paste the private key.');
                } elseif ($settings['auth'] === 'key' && ! str_contains($secrets['private_key'], 'PRIVATE KEY') && ! str_starts_with($secrets['private_key'], 'PuTTY')) {
                    $errors['private_key'] = __('That is not a private key: it begins with -----BEGIN … PRIVATE KEY-----.');
                }
                if ($settings['auth'] === 'password' && $secrets['password'] === '') {
                    $errors['password'] = __('Type the password.');
                }
                if ($settings['fingerprint'] !== '' && ! preg_match('#^SHA256:[A-Za-z0-9+/]{43}$#', $settings['fingerprint'])) {
                    $errors['fingerprint'] = __('A key fingerprint looks like SHA256: and 43 letters and digits.');
                }

                return $this->withProxy([$settings, $secrets, $errors], $input, $old);

            case 'ftp':
                $settings = [
                    'host' => $this->host($text('host')),
                    'port' => $this->port($input['port'] ?? null, 21),
                    'username' => $text('username'),
                    'root' => $this->cleanFolder($text('root')),
                    'ssl' => (bool) ($input['ssl'] ?? false),
                    'passive' => (bool) ($input['passive'] ?? false),
                ];
                $secrets = ['password' => $text('password') !== '' ? (string) $input['password'] : (string) ($old['password'] ?? '')];

                $errors += $this->serverErrors($settings);
                if ($secrets['password'] === '') {
                    $errors['password'] = __('Type the password.');
                }

                return [$settings, $secrets, $errors];

            case 'webdav':
                $settings = [
                    'url' => rtrim($text('url'), '/'),
                    'username' => $text('username'),
                    'root' => $this->cleanFolder($text('root')),
                ];
                $secrets = ['password' => $text('password') !== '' ? (string) $input['password'] : (string) ($old['password'] ?? '')];

                if (! filter_var($settings['url'], FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $settings['url'])) {
                    $errors['url'] = __('The address must be a full URL, https://…');
                }

                return $this->withProxy([$settings, $secrets, $errors], $input, $old);
        }

        if ($kind = $this->extensions()[$driver] ?? null) {
            $checked = $kind->fromInput($input, $existing?->driver === $driver ? $existing : null);

            return $kind->proxied() ? $this->withProxy($checked, $input, $old) : $checked;
        }

        return [[], [], ['driver' => __('Unknown kind of backup storage.')]];
    }

    /**
     * The proxy fields, added to a kind that can take one.
     *
     * @param  array{0: array, 1: array, 2: array}  $checked
     */
    protected function withProxy(array $checked, array $input, array $old): array
    {
        [$settings, $secrets, $errors] = $checked;
        $type = (string) ($input['proxy_type'] ?? 'none');
        $type = in_array($type, Proxy::TYPES, true) ? $type : 'none';

        if ($type === 'none') {
            return [$settings + ['proxy_type' => 'none'], $secrets, $errors];
        }

        $settings += [
            'proxy_type' => $type,
            'proxy_host' => $this->host(trim((string) ($input['proxy_host'] ?? ''))),
            'proxy_port' => $this->port($input['proxy_port'] ?? null, $type === 'socks5' ? 1080 : 3128),
            'proxy_username' => trim((string) ($input['proxy_username'] ?? '')),
        ];
        $typed = (string) ($input['proxy_password'] ?? '');
        $secrets['proxy_password'] = $settings['proxy_username'] === ''
            ? ''
            : ($typed !== '' ? $typed : (string) ($old['proxy_password'] ?? ''));

        if ($settings['proxy_host'] === '') {
            $errors['proxy_host'] = __('Name the proxy\'s address.');
        }

        return [$settings, $secrets, $errors];
    }

    /** @param  array<string, mixed>  $settings */
    protected function serverErrors(array $settings): array
    {
        $errors = [];

        if ($settings['host'] === '') {
            $errors['host'] = __('Name the server: its address without ftp:// or sftp://.');
        }
        if ($settings['username'] === '') {
            $errors['username'] = __('Type the login.');
        }

        return $errors;
    }

    /** "sftp://example.com:2222/" → "example.com": the address, as people paste it. */
    protected function host(string $host): string
    {
        $host = (string) preg_replace('#^[a-z]+://#i', '', trim($host));
        $host = explode('/', $host, 2)[0];
        if (! str_starts_with($host, '[') && substr_count($host, ':') === 1) {
            $host = explode(':', $host)[0];
        }

        return strtolower(rtrim($host, '.'));
    }

    protected function port(mixed $port, int $default): int
    {
        $port = (int) $port;

        return $port >= 1 && $port <= 65535 ? $port : $default;
    }

    /** Why a folder on this server cannot hold backups; null when it can. */
    public function folderError(string $path): ?string
    {
        if ($path === '') {
            return __('Name the folder.');
        }

        $absolute = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) === 1;
        if (! $absolute) {
            return __('The folder must be a full path from the root of the disk.');
        }

        // Never where the web server hands files out: a backup is every
        // customer's data in one file. The public disk and the assets of
        // modules and themes are served as surely as the public folder is.
        $real = $this->resolved($path);
        $served = [public_path(), storage_path('app/public'), base_path('modules'), base_path('themes')];

        foreach ($served as $place) {
            if (str_starts_with($real, $this->resolved($place))) {
                return __('Not inside the site\'s public folder or the site itself: a backup there could be downloaded by anyone.');
            }
        }

        if ($real === $this->resolved(base_path())) {
            return __('Not inside the site\'s public folder or the site itself: a backup there could be downloaded by anyone.');
        }

        if (is_dir($path) && ! is_writable($path)) {
            return __('The folder exists, but this server may not write to it.');
        }

        return null;
    }

    /**
     * Where a path really leads, for comparing, whether or not its end exists
     * yet. As far up as the disk has it the disk answers, which sees through
     * links; `..` in what is left is worked out by hand. A folder not made yet
     * has no `realpath()`, and `storage/../public/new` compared as typed is
     * not inside the public folder.
     */
    protected function resolved(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $missing = [];

        while (($real = realpath($path)) === false && ($parent = dirname($path)) !== $path) {
            array_unshift($missing, basename($path));
            $path = $parent;
        }

        $parts = explode('/', str_replace('\\', '/', $real !== false ? $real : $path));

        foreach ($missing as $part) {
            if ($part === '..') {
                count($parts) > 1 && array_pop($parts);
            } elseif ($part !== '.') {
                $parts[] = $part;
            }
        }

        return strtolower(rtrim(implode('/', $parts), '/')).'/';
    }

    protected function cleanFolder(string $folder): string
    {
        return collect(explode('/', str_replace('\\', '/', $folder)))
            ->map(fn (string $part) => trim($part))
            ->filter(fn (string $part) => $part !== '' && $part !== '.' && $part !== '..')
            ->implode('/');
    }
}

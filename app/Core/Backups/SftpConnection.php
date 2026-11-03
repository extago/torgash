<?php

namespace App\Core\Backups;

use League\Flysystem\PhpseclibV3\ConnectionProvider;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;
use Throwable;

/**
 * An SFTP connection for a backup storage (PLAN §67.1), with the two things
 * flysystem's own provider does not do: a proxy (the socket is opened through
 * it and handed to phpseclib), and the server's key confirmed by the owner on
 * first meeting.
 *
 * With no fingerprint stored a connection is refused with the one the server
 * showed (`HostKeyNotConfirmed`): "Check" puts it on the screen, the owner
 * compares and confirms, and from then on any other key is a refusal in words
 * — a reinstalled server, or somebody else's — never a quiet upload of every
 * customer's data to it.
 */
class SftpConnection implements ConnectionProvider
{
    protected ?SFTP $connection = null;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $username,
        protected string $password = '',
        protected string $privateKey = '',
        protected string $passphrase = '',
        protected string $fingerprint = '',
        protected int $timeout = 20,
        protected ?Proxy $proxy = null,
    ) {}

    public function provideConnection(): SFTP
    {
        if ($this->connection?->isConnected()) {
            return $this->connection;
        }

        [$sftp, $seen] = $this->meet();

        if ($this->fingerprint === '') {
            $sftp->disconnect();

            throw new HostKeyNotConfirmed($seen);
        }

        if (! hash_equals(self::normalise($this->fingerprint), $seen)) {
            $sftp->disconnect();

            throw new RuntimeException("The server's key is $seen, not the one confirmed ({$this->fingerprint}). The server was reinstalled — or it is not your server. Nothing was sent.");
        }

        if (! $sftp->login($this->username, $this->credential())) {
            $sftp->disconnect();

            throw new RuntimeException('The server did not take the login'.($this->privateKey !== '' ? ' and key' : ' and password').'.');
        }

        return $this->connection = $sftp;
    }

    /** The key the server shows now, nothing sent and no login. */
    public function hostKey(): string
    {
        [$sftp, $seen] = $this->meet();
        $sftp->disconnect();

        return $seen;
    }

    /** @return array{0: SFTP, 1: string} */
    protected function meet(): array
    {
        $sftp = $this->proxy
            ? new SFTP($this->proxy->open($this->host, $this->port, $this->timeout), $this->port, $this->timeout)
            : new SFTP($this->host, $this->port, $this->timeout);
        $sftp->disableStatCache();

        $key = $sftp->getServerPublicHostKey();
        if ($key === false) {
            throw new RuntimeException("No SSH server answered at {$this->host}:{$this->port}.".($sftp->getLastError() ? ' '.$sftp->getLastError() : ''));
        }

        return [$sftp, self::fingerprintOf($key)];
    }

    public function disconnect(): void
    {
        $this->connection?->disconnect();
        $this->connection = null;
    }

    /** "SHA256:…" — the form `ssh-keygen -lf` and OpenSSH print. */
    public static function fingerprintOf(string $publicKey): string
    {
        $parts = explode(' ', $publicKey, 3);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) base64_decode($parts[1] ?? ''), true)), '=');
    }

    /** What an owner pasted, in the form compared: "SHA256:" + base64 without padding. */
    public static function normalise(string $fingerprint): string
    {
        $fingerprint = trim($fingerprint);

        if (stripos($fingerprint, 'SHA256:') === 0) {
            $fingerprint = substr($fingerprint, 7);
        }

        return 'SHA256:'.rtrim($fingerprint, '=');
    }

    protected function credential(): mixed
    {
        if ($this->privateKey === '') {
            return $this->password;
        }

        try {
            return $this->passphrase !== ''
                ? PublicKeyLoader::load($this->privateKey, $this->passphrase)
                : PublicKeyLoader::load($this->privateKey);
        } catch (Throwable) {
            throw new RuntimeException('The private key cannot be read'.($this->passphrase !== '' ? ' with that passphrase' : ' (does it need a passphrase?)').'.');
        }
    }
}

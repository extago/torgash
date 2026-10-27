<?php

namespace App\Core\Mail;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * Where letters leave from (PLAN §41): the mail server typed on Admin →
 * Letters, or — when none was — .env, exactly as before. Applied when the
 * mailer is first built (CoreServiceProvider), so every letter, queued or
 * not, goes the same way.
 *
 * The password is stored ENCRYPTED with the app key: a database dump alone
 * does not hand out the mailbox. It is never drawn back into the form — an
 * empty box keeps it, the rule of every credential form here.
 */
class MailServer
{
    /** @return array{enabled: bool, host: string, port: int, encryption: string, username: string, has_password: bool, from_address: string, from_name: string} */
    public static function settings(): array
    {
        return [
            'enabled' => (bool) setting('core', 'smtp_enabled', false),
            'host' => (string) setting('core', 'smtp_host', ''),
            'port' => (int) setting('core', 'smtp_port', 587),
            'encryption' => (string) setting('core', 'smtp_encryption', 'tls'),
            'username' => (string) setting('core', 'smtp_username', ''),
            'has_password' => (string) setting('core', 'smtp_password', '') !== '',
            'from_address' => (string) setting('core', 'mail_from_address', ''),
            'from_name' => (string) setting('core', 'mail_from_name', ''),
        ];
    }

    /** Puts the typed server into the mailer's configuration, when there is one. */
    public static function apply(): void
    {
        $s = self::settings();

        if ($s['from_address'] !== '') {
            config(['mail.from.address' => $s['from_address']]);
        }
        if ($s['from_name'] !== '') {
            config(['mail.from.name' => $s['from_name']]);
        }

        if (! $s['enabled'] || $s['host'] === '') {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $s['host'],
            'mail.mailers.smtp.port' => $s['port'],
            'mail.mailers.smtp.scheme' => $s['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => $s['username'] ?: null,
            'mail.mailers.smtp.password' => self::password(),
        ]);
    }

    public static function password(): ?string
    {
        $stored = (string) setting('core', 'smtp_password', '');

        if ($stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    public static function encrypt(string $password): string
    {
        return Crypt::encryptString($password);
    }

    /**
     * Connects and says hello, sending nothing — the answer to "are these
     * right". The password typed now, or the stored one when the box is empty.
     *
     * @return array{ok: bool, message: string}
     */
    public static function check(string $host, int $port, string $encryption, string $username, ?string $password): array
    {
        try {
            $transport = new EsmtpTransport($host, $port, $encryption === 'ssl' ? true : null);

            if ($encryption === 'none') {
                $transport->setAutoTls(false);
            }
            if ($username !== '') {
                $transport->setUsername($username);
                $transport->setPassword((string) $password);
            }

            $transport->getStream()->setTimeout(10);
            $transport->start();
            $transport->stop();

            return ['ok' => true, 'message' => __('The server answered and accepted the login.')];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => Str::limit($e->getMessage(), 300)];
        }
    }
}

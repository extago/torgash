<?php

namespace App\Core\Verification;

use App\Core\Mail\Letters;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The code by email — core's own channel, because every shop has mail.
 *
 * Sent NOW, not queued: the answer is the point. What can be known at once is
 * known at once — a domain that takes no mail at all (`gmial.com`) is an
 * `invalid_address` before anything is sent, and an SMTP server refusing the
 * recipient outright is one too. A letter that bounces later cannot be
 * reported here; the screen says "no letter within two minutes — check the
 * address" for that.
 */
class EmailChannel implements Channel
{
    /** Domains a development machine uses and no DNS knows. */
    protected const LOCAL_TLDS = ['local', 'localhost', 'test', 'example', 'invalid'];

    public function label(): string
    {
        return 'Email';
    }

    public function kinds(): array
    {
        return ['email'];
    }

    public function available(): bool
    {
        return true;
    }

    public function supports(string $kind, string $address): bool
    {
        return $kind === 'email';
    }

    public function send(string $kind, string $address, array $message): SendResult
    {
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return SendResult::invalidAddress('not an email address');
        }

        if (! $this->domainTakesMail(Str::after($address, '@'))) {
            return SendResult::invalidAddress('the domain has no mail server');
        }

        try {
            // Now, not queued: the channel reports whether it went.
            Letters::send('account.code', $address, [
                'code' => $message['code'],
                'purpose' => $message['purpose'],
                'link' => $message['link'] ?? null,
            ], $message['locale'], now: true);
        } catch (TransportExceptionInterface $e) {
            // 5xx on RCPT is the server saying "no such mailbox" — the one
            // refusal that is about the address, not about the server.
            return preg_match('/\b55[0-3]\b/', $e->getMessage())
                ? SendResult::invalidAddress(Str::limit($e->getMessage(), 250))
                : SendResult::temporary(Str::limit($e->getMessage(), 250));
        } catch (Throwable $e) {
            report($e);

            return SendResult::temporary(Str::limit($e->getMessage(), 250));
        }

        return SendResult::sent();
    }

    /**
     * Whether the domain takes mail: an MX record, or an address record
     * (RFC 5321 falls back to it). A lookup that FAILS — no DNS on this
     * server — answers yes: not being able to ask is not a "no".
     */
    protected function domainTakesMail(string $domain): bool
    {
        $domain = strtolower(rtrim($domain, '.'));

        if (! config('torgash.verification.check_mail_domain', true)
            || in_array(Str::afterLast($domain, '.'), self::LOCAL_TLDS, true)) {
            return true;
        }

        foreach ([DNS_MX, DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($domain.'.', $type);

            if ($records === false) {
                return true;
            }

            if ($records !== []) {
                return true;
            }
        }

        return false;
    }
}

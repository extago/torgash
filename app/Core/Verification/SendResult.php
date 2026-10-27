<?php

namespace App\Core\Verification;

/**
 * What a channel says the moment it was asked to deliver a code — the answer
 * the customer is shown, which is why there are exactly four:
 *
 * - `sent`: handed over; the customer waits for it;
 * - `invalid_address`: the address cannot be right ("check the number") —
 *   the customer typed something that is not an address, and must be told
 *   so rather than wait for a code that will never come;
 * - `unreachable`: the address is fine, THIS channel cannot reach it (no
 *   Telegram on the number, a country the SMS provider does not serve) —
 *   the channel is taken out of the choice and the next one offered;
 * - `temporary`: the provider failed; try again, or another channel.
 */
final class SendResult
{
    public const SENT = 'sent';

    public const INVALID_ADDRESS = 'invalid_address';

    public const UNREACHABLE = 'unreachable';

    public const TEMPORARY = 'temporary';

    private function __construct(public readonly string $status, public readonly ?string $error = null) {}

    /** `$note` is what the provider answered (a message id), kept for the log. */
    public static function sent(?string $note = null): self
    {
        return new self(self::SENT, $note);
    }

    public static function invalidAddress(?string $error = null): self
    {
        return new self(self::INVALID_ADDRESS, $error);
    }

    public static function unreachable(?string $error = null): self
    {
        return new self(self::UNREACHABLE, $error);
    }

    public static function temporary(?string $error = null): self
    {
        return new self(self::TEMPORARY, $error);
    }

    public function ok(): bool
    {
        return $this->status === self::SENT;
    }
}

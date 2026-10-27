<?php

namespace App\Core\Verification;

/**
 * A way of getting a code to a person (PLAN §44.3). Core ships email; a
 * module adds its own by answering `core.verification.channels` with
 * `['key' => instance]` — an HTTP request to an SMS gateway, a messenger.
 *
 * `send()` answers IMMEDIATELY (SendResult): the customer is looking at the
 * screen, and "that number cannot be right" is worth more to them than a code
 * that never arrives.
 */
interface Channel
{
    /** English source label, shown to the customer ("Email", "SMS"). */
    public function label(): string;

    /** @return list<string> which kinds of address it delivers to: 'email', 'phone' */
    public function kinds(): array;

    /** Whether it can be offered at all right now (configured, within budget). */
    public function available(): bool;

    /** Whether it can deliver to this particular address (a country it serves). */
    public function supports(string $kind, string $address): bool;

    /**
     * @param  array{code: string, purpose: string, link: ?string, locale: string, user: ?\App\Models\User}  $message
     */
    public function send(string $kind, string $address, array $message): SendResult;
}

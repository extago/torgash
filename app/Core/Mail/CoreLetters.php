<?php

namespace App\Core\Mail;

use App\Core\Verification\Verifier;

/**
 * The letters core itself sends (PLAN §41): a code proving an address, and
 * the warning to an account's OLD email that it was changed. Each module
 * registers its own the same way, answering `core.mail.events`.
 */
class CoreLetters
{
    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        return [
            'account.code' => [
                'label' => 'A code to confirm an address',
                'group' => 'Account',
                'audience' => 'customer',
                // Signing in and getting back in depend on it.
                'required' => true,
                'subject' => '{code} is your code for {shop_name}',
                'heading' => '{purpose}',
                'body' => "{code_block}\n\nThe code works for {minutes} minutes. If you did not ask for it, ignore this letter — nothing happens without the code.",
                'button' => ['label' => 'Continue on this device', 'url' => '{link}'],
                'help' => [
                    'code' => 'The six digits',
                    'purpose' => 'What the code is for',
                    'minutes' => 'How long it works',
                    'code_block' => 'The code, large (on a line of its own)',
                ],
                'tokens' => fn (array $c) => [
                    'code' => (string) ($c['code'] ?? ''),
                    'purpose' => Verifier::purposeLabel((string) ($c['purpose'] ?? '')),
                    'minutes' => (string) Verifier::TTL_MINUTES,
                    'link' => (string) ($c['link'] ?? ''),
                ],
                'blocks' => fn (array $c) => [
                    'code_block' => '<p style="margin:4px 0 18px; font-size:34px; font-weight:700; letter-spacing:8px; color:#0f172a;">'.e((string) ($c['code'] ?? '')).'</p>',
                ],
                'sample' => fn () => ['code' => '482913', 'purpose' => 'password_reset', 'link' => url('/')],
            ],

            'account.staff_reset' => [
                'label' => 'Staff: a link to set a new password',
                'group' => 'Staff',
                'audience' => 'shop',
                'required' => true,
                'subject' => 'A new password for the admin of {shop_name}',
                'heading' => 'Hello, {name}.',
                'body' => "Somebody asked to set a new password for your access to the admin panel. The link works for {valid_for}.\n\nIf it was not you, ignore this letter — the old password keeps working.",
                'button' => ['label' => 'Set a new password', 'url' => '{link}'],
                'help' => ['name' => 'Their name', 'valid_for' => 'How long the link works'],
                'tokens' => fn (array $c) => self::linkTokens($c, __('an hour')),
                'sample' => fn () => ['name' => 'Olena', 'link' => url('/admin')],
            ],

            'account.staff_invite' => [
                'label' => 'Staff: an invitation to the admin panel',
                'group' => 'Staff',
                'audience' => 'shop',
                'required' => true,
                'subject' => 'Your access to the admin of {shop_name}',
                'heading' => 'Hello, {name}.',
                'body' => "An account in the admin panel of {shop_name} was made for you. Choose your password with the button below — the link works for {valid_for}.\n\nYou sign in with this address: {email}.",
                'button' => ['label' => 'Set my password', 'url' => '{link}'],
                'help' => ['name' => 'Their name', 'email' => 'The address they sign in with', 'valid_for' => 'How long the link works'],
                'tokens' => fn (array $c) => self::linkTokens($c, __('7 days')),
                'sample' => fn () => ['name' => 'Olena', 'email' => 'manager@example.com', 'link' => url('/admin')],
            ],

            'account.customer_invite' => [
                'label' => 'An account made for a customer',
                'group' => 'Account',
                'audience' => 'customer',
                'subject' => 'Your account at {shop_name}',
                'heading' => 'Hello, {name}.',
                'body' => "An account at {shop_name} was made for you — your orders and saved addresses are in it. Choose your password with the button below; the link works for {valid_for}.",
                'button' => ['label' => 'Set my password', 'url' => '{link}'],
                'help' => ['name' => 'Their name', 'email' => 'The address they sign in with', 'valid_for' => 'How long the link works'],
                'tokens' => fn (array $c) => self::linkTokens($c, __('7 days')),
                'sample' => fn () => ['name' => 'Olena', 'email' => 'olena@example.com', 'link' => url('/')],
            ],

            'account.email_changed' => [
                'label' => 'The email of an account was changed',
                'group' => 'Account',
                'audience' => 'customer',
                // The owner's one chance to learn that somebody else did it.
                'required' => true,
                'subject' => 'The email of your account at {shop_name} was changed',
                'heading' => 'Hello, {name}.',
                'body' => "The email of your account was changed to {new_email}. Letters about your orders will go there from now on.\n\nIf it was not you, reply to this letter or contact the shop at once.",
                'help' => [
                    'name' => 'The customer\'s name',
                    'new_email' => 'The new address, partly hidden',
                ],
                'tokens' => fn (array $c) => [
                    'name' => (string) ($c['name'] ?? ''),
                    'new_email' => Verifier::mask('email', (string) ($c['new_email'] ?? '')),
                ],
                'sample' => fn () => ['name' => 'Olena', 'new_email' => 'olena.new@example.com'],
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function linkTokens(array $c, string $validFor): array
    {
        return [
            'name' => (string) ($c['name'] ?? ''),
            'email' => (string) ($c['email'] ?? ''),
            'link' => (string) ($c['link'] ?? ''),
            'valid_for' => $validFor,
        ];
    }
}

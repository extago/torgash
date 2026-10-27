<?php

namespace Torgash\Newsletter;

use App\Core\Mail\Letters;
use App\Models\User;
use Illuminate\Support\Str;
use Torgash\Newsletter\Models\Subscriber;

/**
 * The one place a subscriber's state changes — whichever door it came
 * through (the block, the basket, checkout, the account, the admin) — and
 * the one that announces it (`newsletter.subscriber.changed`, for a mailing
 * service's connector or an automation).
 *
 * Double opt-in (setting `double_opt_in`): a new address waits for the link in
 * its letter. Not asked again: an address already subscribed, and a signed-in
 * customer subscribing their own CONFIRMED address — the account has proved it.
 * Asking again for an address that is waiting re-sends the letter, at most
 * once in ten minutes (a form submitted twice is not two letters).
 */
class Subscriptions
{
    public const LETTER = 'newsletter.confirm';

    /** Minutes before the confirmation letter may be sent again to the same address. */
    public const RESEND_AFTER = 10;

    /**
     * @return string `subscribed` (done), `confirm` (a letter is on its way),
     *                `already` (nothing to do), `invalid`
     */
    public function subscribe(string $email, string $source, ?User $user = null, ?string $consentText = null): string
    {
        $email = Str::lower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'invalid';
        }

        $subscriber = Subscriber::firstOrNew(['email' => $email]);

        if ($subscriber->exists && $subscriber->isSubscribed()) {
            return 'already';
        }

        $subscriber->fill([
            'source' => in_array($source, Subscriber::SOURCES, true) ? $source : 'block',
            'storefront_id' => current_storefront()?->id,
            // The language of the storefront page they subscribed on — their
            // letters' language. An import or the admin is not the reader's
            // page: no language (the shop's base one), or the one it had.
            'locale' => in_array($source, ['import', 'admin'], true) ? $subscriber->locale : app()->getLocale(),
            'user_id' => $user?->id ?? $subscriber->user_id,
            'consent_ip' => request()?->ip(),
            'consent_text' => $consentText,
            'token' => $subscriber->token ?: Str::random(40),
        ]);

        $trusted = $user !== null && Str::lower((string) $user->email) === $email && $user->email_verified_at !== null;

        if (! setting('torgash.newsletter', 'double_opt_in', true) || $trusted || $source === 'import' || $source === 'admin') {
            $this->mark($subscriber, Subscriber::SUBSCRIBED);

            return 'subscribed';
        }

        $subscriber->status = Subscriber::PENDING;
        $resend = $subscriber->confirmation_sent_at === null || $subscriber->confirmation_sent_at->lt(now()->subMinutes(self::RESEND_AFTER));
        if ($resend) {
            $subscriber->confirmation_sent_at = now();
        }
        $subscriber->save();

        if ($resend) {
            Letters::send(self::LETTER, $subscriber->email, ['subscriber' => $subscriber, 'storefront_id' => $subscriber->storefront_id], $subscriber->locale);
        }

        return 'confirm';
    }

    /** The checkout tick, when the order is placed — read only where the shop offers it. */
    public function fromOrder(string $email, string $number, bool $ticked): void
    {
        if ($ticked && setting('torgash.newsletter', 'offer_at_checkout', true)) {
            $this->subscribe($email, 'checkout', request()?->user(), __('Ticked at checkout, order :number.', ['number' => $number]));
        }
    }

    /** The link in the letter: the address is theirs. False for a token nobody has. */
    public function confirm(string $token): ?Subscriber
    {
        $subscriber = Subscriber::where('token', $token)->first();

        if ($subscriber && ! $subscriber->isSubscribed()) {
            $this->mark($subscriber, Subscriber::SUBSCRIBED);
        }

        return $subscriber;
    }

    public function unsubscribe(Subscriber $subscriber): void
    {
        if ($subscriber->status !== Subscriber::UNSUBSCRIBED) {
            $this->mark($subscriber, Subscriber::UNSUBSCRIBED);
        }
    }

    /** The account's own address, if it is on the list at all. */
    public function of(User $user): ?Subscriber
    {
        return Subscriber::where('user_id', $user->id)->orWhere('email', Str::lower((string) $user->email))->first();
    }

    protected function mark(Subscriber $subscriber, string $status): void
    {
        $was = $subscriber->exists ? $subscriber->getOriginal('status') : null;
        $subscriber->status = $status;

        if ($status === Subscriber::SUBSCRIBED) {
            $subscriber->confirmed_at = now();
            $subscriber->unsubscribed_at = null;
        } elseif ($status === Subscriber::UNSUBSCRIBED) {
            $subscriber->unsubscribed_at = now();
        }

        $subscriber->save();

        hook_action('newsletter.subscriber.changed', ['subscriber' => $subscriber, 'from' => $was, 'to' => $status]);
    }

    /** @return array<string, array<string, mixed>> the confirmation letter, in core's registry */
    public static function letters(): array
    {
        return [
            self::LETTER => [
                'label' => 'Newsletter: confirm the subscription',
                'group' => 'Newsletter',
                'audience' => 'customer',
                // Nobody is on the list without it.
                'required' => true,
                'subject' => 'Confirm your subscription to {shop_name}',
                'heading' => 'One click and you are subscribed.',
                'body' => "Somebody — we hope you — asked to receive news and offers from {shop_name} at this address.\n\nIf it was not you, ignore this letter: nothing is sent without the click.",
                'button' => ['label' => 'Confirm the subscription', 'url' => '{confirm_url}'],
                'help' => ['confirm_url' => 'The link that confirms the subscription'],
                'tokens' => fn (array $c) => [
                    'confirm_url' => ($c['subscriber'] ?? null) instanceof Subscriber ? route('newsletter.confirm', $c['subscriber']->token) : '',
                ],
                'sample' => fn () => ['subscriber' => new Subscriber(['email' => 'reader@example.com', 'token' => str_repeat('x', 40)])],
            ],
        ];
    }
}

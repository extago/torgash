<?php

namespace Torgash\Enquiries;

use App\Core\Mail\Letters;
use Torgash\Enquiries\Mail\EnquiryLetters;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;

/**
 * The mail this module sends by itself, and the one place that decides who an
 * address belongs to.
 *
 * Why not left to an automations rule: an enquiry nobody has been told about is
 * indistinguishable from a form that does not work, and "it works once you
 * configure a rule" means a shop loses its first week. Everything BEYOND these
 * three — Telegram, a webhook, a reminder — is a rule off
 * `enquiries.enquiry.created` / `enquiries.message.created`, which are
 * announced whether or not any address is configured here.
 *
 * All three are queued mailables, so the person who clicked never waits on SMTP
 * and a mail host that is down cannot turn a stored row into an error page.
 */
class Notify
{
    /**
     * A new enquiry, to the shop.
     *
     * Lives here rather than in Submit so that "where does the shop read its
     * inbox" is answered in one place — two copies of that fallback would drift
     * the first time somebody added a per-storefront address.
     */
    public static function received(Enquiry $enquiry): void
    {
        // A form the shop built may send its letters somewhere of its own —
        // the job applications to HR, not to sales (PLAN §48). Instead of the
        // shop's inbox, not as well: that is the point of naming one.
        $own = trim((string) (Kinds::find($enquiry->kind)['form'] ?? null)?->notify_email);

        if ($own !== '') {
            Letters::send(EnquiryLetters::RECEIVED, array_map('trim', explode(',', $own)), ['enquiry' => $enquiry], config('app.locale'));

            return;
        }

        // To the addresses set on Admin → Letters, else the inbox below.
        Letters::toShop(EnquiryLetters::RECEIVED, ['enquiry' => $enquiry]);
    }

    /**
     * A new message, to whichever side did not write it.
     *
     * The customer's copy is the point of the whole exercise for a GUEST: they
     * have no account to come back to (§29.3/1), so the email is the answer.
     * It is sent only when there is an address to send it to — a callback left
     * with a phone number and nothing else gets no mail rather than a mail to
     * an address composed out of thin air.
     */
    public static function about(Message $message, Enquiry $enquiry): void
    {
        if ($message->is_staff) {
            $to = trim((string) $enquiry->email);

            if ($to !== '') {
                // In the language the enquiry was WRITTEN in, not the worker's.
                Letters::send(EnquiryLetters::REPLIED, $to, ['enquiry' => $enquiry, 'message' => $message], $enquiry->locale ?: null);
            }

            return;
        }

        Letters::toShop(EnquiryLetters::MESSAGE, ['enquiry' => $enquiry, 'message' => $message]);
    }

    /** The configured inbox, else the storefront's own contact address. */
    public static function shopAddress(): string
    {
        return trim((string) setting('torgash.enquiries', 'notify_email', ''))
            ?: trim((string) (current_storefront()?->contact('email') ?? ''));
    }
}

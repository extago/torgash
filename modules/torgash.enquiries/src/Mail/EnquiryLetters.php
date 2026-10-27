<?php

namespace Torgash\Enquiries\Mail;

use Illuminate\Support\Facades\Route;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;
use Torgash\Enquiries\Notify;

/**
 * The enquiry module's letters in core's registry (PLAN §41). Two to the
 * shop — a new enquiry, a new message in a thread — whose "Reply" goes to
 * the customer; one to the customer — the shop's answer — whose "Reply"
 * goes to the shop, because a guest has no account to answer in. What the
 * person typed is a BLOCK: their words are quoted as written, never
 * retyped into a template.
 */
class EnquiryLetters
{
    public const RECEIVED = 'enquiries.received';

    public const MESSAGE = 'enquiries.message';

    public const REPLIED = 'enquiries.replied';

    /** @return array<string, array<string, mixed>> */
    public static function events(): array
    {
        $shop = fn () => array_filter([Notify::shopAddress()]);
        $customerReply = fn (array $c) => ($c['enquiry'] ?? null)?->email;

        return [
            self::RECEIVED => [
                'label' => 'A new enquiry',
                'group' => 'Enquiries',
                'audience' => 'shop',
                'subject' => '{kind} from {who}',
                'heading' => '{kind}',
                'body' => "{enquiry_details}",
                'button' => ['label' => 'Open in the admin panel', 'url' => '{admin_url}'],
                'help' => self::help(),
                'tokens' => fn (array $c) => self::tokens($c['enquiry'] ?? null),
                'blocks' => fn (array $c) => self::blocks($c['enquiry'] ?? null, null, true),
                'reply_to' => $customerReply,
                'recipients' => $shop,
                'sample' => fn () => ['enquiry' => Enquiry::latest('id')->first()],
            ],
            self::MESSAGE => [
                'label' => 'A new message in an enquiry',
                'group' => 'Enquiries',
                'audience' => 'shop',
                'subject' => 'New message: {kind}',
                'heading' => 'New message from {who}',
                'body' => "{message}",
                'button' => ['label' => 'Open in the admin panel', 'url' => '{admin_url}'],
                'help' => self::help() + ['message' => 'What they wrote (on a line of its own)'],
                'tokens' => fn (array $c) => self::tokens($c['enquiry'] ?? null, $c['message'] ?? null),
                'blocks' => fn (array $c) => self::blocks($c['enquiry'] ?? null, $c['message'] ?? null, true),
                'reply_to' => $customerReply,
                'recipients' => $shop,
                'sample' => fn () => self::sampleMessage(false),
            ],
            self::REPLIED => [
                'label' => 'The shop\'s answer to an enquiry',
                'group' => 'Enquiries',
                'audience' => 'customer',
                'subject' => 'An answer to your enquiry: {kind}',
                'heading' => 'Here is our answer to your enquiry.',
                'body' => "{message}\n\n{enquiry_details}\n\n{how_to_answer}",
                'button' => ['label' => 'Read the conversation', 'url' => '{thread_url}'],
                'help' => self::help() + [
                    'message' => 'The answer (on a line of its own)',
                    'how_to_answer' => 'How they can answer',
                    'thread_url' => 'The link to the conversation (an account only)',
                ],
                'tokens' => fn (array $c) => self::tokens($c['enquiry'] ?? null, $c['message'] ?? null),
                'blocks' => fn (array $c) => self::blocks($c['enquiry'] ?? null, $c['message'] ?? null, false),
                'reply_to' => fn () => Notify::shopAddress() ?: null,
                'sample' => fn () => self::sampleMessage(true),
            ],
        ];
    }

    /** @return array<string, string> */
    protected static function help(): array
    {
        return [
            'kind' => 'What kind of enquiry',
            'who' => 'Who sent it',
            'admin_url' => 'The link to it in the admin',
            'enquiry_details' => 'What they typed, with the labels (on a line of its own)',
        ];
    }

    /** @return array<string, string> */
    protected static function tokens(?Enquiry $enquiry, ?Message $message = null): array
    {
        if (! $enquiry) {
            return [];
        }

        return [
            'kind' => $enquiry->kindLabel(),
            'who' => (string) ($message && ! $message->is_staff ? $message->authorName() : ($enquiry->name ?: ($enquiry->phone ?: $enquiry->email))),
            'admin_url' => Route::has('admin.enquiries.show') ? route('admin.enquiries.show', $enquiry) : '',
            'thread_url' => self::threadUrl($enquiry),
            // A guest cannot answer in the thread — they have no account — so
            // "Reply" is how, and it reaches the shop (reply_to).
            'how_to_answer' => $enquiry->user_id ? '' : __('You can answer by replying to this email.'),
        ];
    }

    /** @return array<string, string> */
    protected static function blocks(?Enquiry $enquiry, ?Message $message, bool $forShop): array
    {
        if (! $enquiry) {
            return [];
        }

        return [
            'enquiry_details' => view('torgash.enquiries::mail.details', ['enquiry' => $enquiry, 'forShop' => $forShop])->render(),
            'message' => $message
                ? '<div style="border-left:3px solid #cbd5e1; padding:6px 14px; margin:0 0 16px; white-space:pre-line;">'.e((string) $message->body).'</div>'
                : '',
        ];
    }

    /**
     * Where the customer reads the conversation — only with an account; in
     * the locale's shape, since a worker has no request to take it from.
     */
    protected static function threadUrl(Enquiry $enquiry): string
    {
        if (! $enquiry->user_id) {
            return '';
        }

        $name = 'account.enquiries.show';
        $locale = $enquiry->locale;

        if ($locale && $locale !== $enquiry->storefront?->default_locale && Route::has($locale.'.'.$name)) {
            $name = $locale.'.'.$name;
        }

        return Route::has($name) ? route($name, $enquiry) : '';
    }

    /** @return array<string, mixed>|null */
    protected static function sampleMessage(bool $staff): ?array
    {
        $message = Message::where('is_staff', $staff)->latest('id')->first();

        return $message ? ['enquiry' => $message->enquiry, 'message' => $message] : ['enquiry' => null];
    }
}

<?php

namespace Torgash\Automations\Actions;

use App\Core\Mail\Letters;
use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Template;

/**
 * A letter to the customer the order or enquiry is about — bank details for
 * a transfer, "we could not reach you".
 *
 * Through core's letters like every letter of the shop (`automations.letter`,
 * not editable on Admin → Letters: its words are the rule's), so it gets the
 * shop's frame, the theme and the domain of the storefront the order came
 * from, and goes out queued. The words are the template's: values said as
 * they are (Template::RAW) — the letter escapes them itself, and a blank line
 * starts a paragraph.
 */
class EmailAction implements Action, HasFields
{
    public const EVENT = 'automations.letter';

    public static function label(): string
    {
        return 'Email the customer';
    }

    public static function subjects(): array
    {
        return ['order', 'enquiry'];
    }

    public static function fields(): array
    {
        return [
            'subject' => ['label' => 'Subject', 'type' => 'text'],
            'text' => [
                'label' => 'Letter',
                'type' => 'textarea',
                'help' => 'Plain text with the variables; a blank line starts a paragraph. Sent in the shop\'s letter frame to the email the customer gave.',
            ],
        ];
    }

    /** The registry entry (core.mail.events). @return array<string, array<string, mixed>> */
    public static function event(): array
    {
        return [
            self::EVENT => [
                'label' => 'A letter from an automation rule',
                'group' => 'Automations',
                'audience' => 'customer',
                'editable' => false,
                'subject' => '{subject}',
                'heading' => '{subject}',
                'body' => '{text}',
                'tokens' => fn (array $c) => ['subject' => (string) ($c['subject'] ?? '')],
                // A block, so the rule's line breaks survive: escaped, then
                // a blank line a paragraph and a single one a break.
                'blocks' => fn (array $c) => ['text' => collect(preg_split('/\R{2,}/', trim((string) ($c['text'] ?? ''))) ?: [])
                    ->map(fn (string $paragraph) => '<p>'.nl2br(e(trim($paragraph))).'</p>')->implode('')],
            ],
        ];
    }

    public function run(RuleAction $action, Model $subject, array $context, string $idempotencyKey): array
    {
        $email = trim((string) $subject->getAttribute('email'));
        $title = trim(Template::render((string) $action->config('subject', ''), $context, Template::RAW));
        $text = trim(Template::render((string) $action->config('text', ''), $context, Template::RAW));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => RuleRun::SKIPPED, 'summary' => __('The customer left no email.'), 'detail' => []];
        }

        if ($title === '' || $text === '') {
            return ['status' => RuleRun::FAILED, 'summary' => __('The letter has no subject or no text.'), 'detail' => []];
        }

        $sent = Letters::send(self::EVENT, $email, [
            'subject' => $title,
            'text' => $text,
            // What the letter is about: Letters finds its storefront (theme,
            // domain) from it.
            'record' => $subject,
        ], (string) ($subject->getAttribute('locale') ?: content_base_locale()));

        return $sent
            ? ['status' => RuleRun::SENT, 'summary' => __('To :email', ['email' => $email]), 'detail' => ['subject' => $title]]
            : ['status' => RuleRun::FAILED, 'summary' => __('The letter is switched off on Admin → Letters.'), 'detail' => []];
    }
}

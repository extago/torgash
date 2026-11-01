<?php

namespace Torgash\Automations\Actions;

use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Template;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;

/**
 * A line in the order's history — "payment reminder sent" — so a manager
 * opening the order sees what the rules did to it without the run log.
 *
 * An order's line goes into its history (OrderLog). Anything else is offered
 * to whichever module owns it on `automations.note` (`{subject, text, rule}`;
 * true when written) — the enquiries module keeps it in the enquiry's notes.
 */
class NoteAction implements Action, HasFields
{
    public static function label(): string
    {
        return 'Add a note to the history';
    }

    public static function subjects(): array
    {
        return ['order', 'enquiry'];
    }

    public static function fields(): array
    {
        return [
            'text' => [
                'label' => 'Note',
                'type' => 'textarea',
                'help' => 'Seen by the staff only, beside the rule\'s name. Variables work here too.',
            ],
        ];
    }

    public function run(RuleAction $action, Model $subject, array $context, string $idempotencyKey): array
    {
        $text = trim(Template::render((string) $action->config('text', ''), $context, Template::RAW));
        $rule = (string) ($action->rule?->name ?? '');

        if ($text === '') {
            return ['status' => RuleRun::SKIPPED, 'summary' => __('The note is empty.'), 'detail' => []];
        }

        if ($subject instanceof Order) {
            OrderLog::record($subject, ':rule: :text', [
                'rule' => $rule !== '' ? $rule : OrderLog::word('Automation'),
                'text' => $text,
            ], by: null);
        } elseif (! hook_filter('automations.note', false, ['subject' => $subject, 'text' => $text, 'rule' => $rule])) {
            return ['status' => RuleRun::FAILED, 'summary' => __('Nothing here keeps notes.'), 'detail' => []];
        }

        return ['status' => RuleRun::SENT, 'summary' => mb_strimwidth($text, 0, 120, '…'), 'detail' => []];
    }
}

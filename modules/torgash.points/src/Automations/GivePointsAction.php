<?php

namespace Torgash\Points\Automations;

use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Actions\Action;
use Torgash\Automations\Actions\HasFields;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Template;
use Torgash\Checkout\Models\Order;
use Torgash\Points\Ledger;

/**
 * "Give bonus points" as a rule's action (PLAN §74, stage 3): the customer of
 * whatever the rule is about gets a number of points at once, with a reason
 * they read in their history — "for an order paid by card", "sorry for the
 * delay". Loaded only when torgash.automations asks for actions, so a shop
 * without it never touches its interfaces.
 *
 * At once, not "waiting": this is the shop's gift, decided by the rule's own
 * conditions and delay. What an order earns BY BEING BOUGHT is the order's
 * (`OrderFlow`), and a promotion's points ride with that.
 *
 * Given once per event: the ledger line carries the run's key, so a retry or
 * a second worker finds it there.
 */
class GivePointsAction implements Action, HasFields
{
    public static function label(): string
    {
        return 'Give bonus points';
    }

    public static function subjects(): array
    {
        return [];
    }

    public static function fields(): array
    {
        return [
            'points' => [
                'label' => 'Points',
                'type' => 'text',
                'help' => 'A whole number, given at once to the customer\'s account. A guest gets nothing.',
            ],
            'reason' => [
                'label' => 'Reason',
                'type' => 'text',
                'help' => 'The customer reads it in their points history. Variables work here too.',
            ],
        ];
    }

    public function run(RuleAction $action, Model $subject, array $context, string $idempotencyKey): array
    {
        $points = (int) preg_replace('/\D/', '', (string) $action->config('points', ''));
        $userId = (int) ($subject->user_id ?? 0);

        if ($points <= 0) {
            return ['status' => RuleRun::FAILED, 'summary' => __('The number of points is not set.'), 'detail' => []];
        }

        if ($userId <= 0) {
            return ['status' => RuleRun::SKIPPED, 'summary' => __('No account to give points to.'), 'detail' => []];
        }

        $reason = trim(Template::render((string) $action->config('reason', ''), $context, Template::RAW))
            ?: (string) ($action->rule?->name ?? __('Bonus points'));

        $entry = app(Ledger::class)->bonus(
            $userId,
            $points,
            mb_strimwidth($reason, 0, 500, '…'),
            'automation:'.$idempotencyKey.':'.$action->id,
            $subject instanceof Order ? (int) $subject->id : null,
        );

        return $entry
            ? ['status' => RuleRun::SENT, 'summary' => '+'.$points, 'detail' => ['user_id' => $userId]]
            : ['status' => RuleRun::SKIPPED, 'summary' => __('Already given for this event.'), 'detail' => []];
    }
}

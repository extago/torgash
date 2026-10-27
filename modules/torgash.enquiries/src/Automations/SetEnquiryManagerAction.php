<?php

namespace Torgash\Enquiries\Automations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Actions\Action;
use Torgash\Automations\Actions\HasFields;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;

/**
 * Give the enquiry to somebody (or take it back) — "every product question to
 * the person who knows the catalogue". Staff only, the same rule the order's
 * own action keeps: a customer holding an enquiry would be somebody who cannot
 * open the inbox.
 */
class SetEnquiryManagerAction implements Action, HasFields
{
    public static function label(): string
    {
        return 'Assign the enquiry to a manager';
    }

    public static function subjects(): array
    {
        return ['enquiry'];
    }

    public static function fields(): array
    {
        return ['manager_id' => [
            'label' => 'Send to',
            'type' => 'select',
            'options' => ['' => __('Not assigned')] + User::query()->staff()->orderBy('name')->pluck('name', 'id')->all(),
        ]];
    }

    public function run(RuleAction $action, Model $enquiry, array $context, string $idempotencyKey): array
    {
        $managerId = $action->config('manager_id');
        $managerId = $managerId === '' || $managerId === null ? null : (int) $managerId;

        if ($managerId !== null && ! User::query()->staff()->whereKey($managerId)->exists()) {
            return ['status' => RuleRun::FAILED, 'summary' => __('That account is not a staff member.'), 'detail' => ['manager_id' => $managerId]];
        }

        if ($enquiry->manager_id === $managerId) {
            return ['status' => RuleRun::SKIPPED, 'summary' => __('Already assigned'), 'detail' => []];
        }

        $was = $enquiry->manager?->name ?? __('unassigned');
        $enquiry->manager_id = $managerId;
        $enquiry->save();
        $enquiry->load('manager');

        return ['status' => RuleRun::SENT, 'summary' => $was.' → '.($enquiry->manager?->name ?? __('unassigned')), 'detail' => []];
    }
}

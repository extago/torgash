<?php

namespace Torgash\Enquiries\Automations;

use Illuminate\Database\Eloquent\Model;
use Torgash\Automations\Actions\Action;
use Torgash\Automations\Actions\HasFields;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Enquiries\Status;

/**
 * Move the enquiry to a state — the inbox's own vocabulary, with its one side
 * effect: the first move into a finished state stamps `answered_at`, exactly
 * as the admin's select does. Rejecting locks the thread by itself (see
 * Enquiry::isLocked()).
 */
class SetEnquiryStatusAction implements Action, HasFields
{
    public static function label(): string
    {
        return 'Change the enquiry status';
    }

    public static function subjects(): array
    {
        return ['enquiry'];
    }

    public static function fields(): array
    {
        return ['status' => ['label' => 'Send to', 'type' => 'select', 'options' => Status::options()]];
    }

    public function run(RuleAction $action, Model $enquiry, array $context, string $idempotencyKey): array
    {
        $status = (string) $action->config('status');

        if (! in_array($status, Status::ALL, true)) {
            return ['status' => RuleRun::FAILED, 'summary' => __('No such status: :code', ['code' => $status]), 'detail' => []];
        }

        if ($enquiry->status === $status) {
            return ['status' => RuleRun::SKIPPED, 'summary' => __('Already :status', ['status' => Status::label($status)]), 'detail' => []];
        }

        $was = Status::label((string) $enquiry->status);
        $enquiry->status = $status;
        if (! Status::isOpen($status) && ! $enquiry->answered_at) {
            $enquiry->answered_at = now();
        }
        $enquiry->save();

        return ['status' => RuleRun::SENT, 'summary' => $was.' → '.Status::label($status), 'detail' => []];
    }
}

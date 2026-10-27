<?php

namespace Torgash\Privacy;

use App\Core\Privacy\PersonalData;
use App\Models\User;
use RuntimeException;
use Torgash\Privacy\Models\PrivacyRequest;

/**
 * Carrying out a request (PLAN §49) — the one place it is done, so the report,
 * the staff guard and the account's deletion cannot be forgotten by a caller.
 *
 * Erasing walks every owner in core's personal-data registry; each says what
 * it removed, what it made anonymous and what it keeps by law (orders: for
 * the books, and in 1C as well — the report says so). The ACCOUNT goes last,
 * through the model, so every `core.user.deleting` listener still runs.
 */
class Requests
{
    public function __construct(protected PersonalData $data) {}

    public static function open(?User $user, string $type, ?string $name, ?string $email, ?string $phone, ?string $note = null): PrivacyRequest
    {
        return PrivacyRequest::create([
            'user_id' => $user?->id,
            'type' => $type,
            'status' => PrivacyRequest::NEW,
            // Contacts only as the caller vouches for them: a guest order
            // under an email is that email's owner's, and an account may hold
            // an address nobody ever confirmed (see AccountPrivacyController).
            'name' => $name ?? $user?->name,
            'email' => $email,
            'phone' => $phone,
            'note' => $note,
            'due_at' => now()->addDays(max(1, (int) setting('torgash.privacy', 'answer_days', 30))),
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function export(PrivacyRequest $request): array
    {
        return $this->data->export($request->subject());
    }

    /**
     * @throws RuntimeException for a member of staff — their account is the
     *                          shop's to close, not a customer's data to erase
     */
    public function erase(PrivacyRequest $request, User $by): array
    {
        $subject = $request->subject();

        if ($subject->isEmpty()) {
            throw new RuntimeException(__('There is nothing to find this person by — no account, email or phone.'));
        }

        if ($subject->user && User::staff()->whereKey($subject->user->id)->exists()) {
            throw new RuntimeException(__('This is a staff account. Close it on the Administrators screen.'));
        }

        $report = $this->data->erase($subject);

        if ($subject->user) {
            $subject->user->delete();
            $report[] = ['key' => 'account', 'label' => __('Account'), 'count' => 1, 'keep' => null];
        }

        $request->update([
            'status' => PrivacyRequest::DONE,
            'report' => $report,
            'done_at' => now(),
            'done_by' => $by->id,
        ]);

        return $report;
    }
}

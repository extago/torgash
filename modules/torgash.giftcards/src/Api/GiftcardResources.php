<?php

namespace Torgash\Giftcards\Api;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Torgash\Api\ApiResources;
use Torgash\Giftcards\Delivery;
use Torgash\Giftcards\Issuer;
use Torgash\Giftcards\Ledger;
use Torgash\Giftcards\Models\Card;

/**
 * Gift cards over the admin API (PLAN §40.4) — what gift-card add-ons elsewhere could not
 * be reached for at all: issue one, read its history, correct it, switch it
 * off. The code is never in a list; it is answered once, by `create`, and
 * otherwise read on the admin screen.
 *
 * Everything that moves money is an ACTION through the ledger, never a PATCH
 * of `balance`: the balance is the sum of the history, and a written column
 * would be a balance with no history behind it.
 */
class GiftcardResources
{
    public function register(): void
    {
        ApiResources::register('admin.giftcards', [
            'label' => 'Gift cards',
            'description' => 'Cards with a balance: money paid in advance. Issued here, by an order that bought one, or in a batch for printing. The balance is the sum of the history (`entries`); corrections are an action, never a write of the balance.',
            'permission' => 'giftcards.manage',
            'query' => fn () => Card::query(),
            'sortable' => ['id', 'balance', 'expires_at', 'created_at', 'updated_at'],
            'filters' => [
                'state' => [
                    'description' => 'active | pending | redeemed | expired | disabled — worked out from the status, the balance and the date together.',
                    'apply' => fn ($q, $v) => $q->inState((string) $v),
                ],
                'code' => [
                    'description' => 'The whole code, in any spelling — finds its card (or nothing).',
                    'apply' => fn ($q, $v) => $q->where('code_hash', \Torgash\Giftcards\Codes::hash(\Torgash\Giftcards\Codes::normalise((string) $v))),
                ],
                'batch' => ['description' => 'A printed batch\'s name.', 'apply' => fn ($q, $v) => $q->where('batch', $v)],
                'owner' => ['description' => 'The account (user id) it belongs to.', 'apply' => fn ($q, $v) => $q->where('owner_user_id', (int) $v)],
                'order' => ['description' => 'The order (id) that bought it.', 'apply' => fn ($q, $v) => $q->where('order_id', (int) $v)],
                'updated_after' => ['description' => 'Everything touched since a moment (ISO 8601).', 'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v)],
            ],
            'serialize' => fn (Card $card) => $this->card($card),
            'schema' => [
                'id' => 'integer',
                'last4' => ['type' => 'string', 'description' => 'How the card is named where its code must not be.'],
                'code' => ['type' => 'string', 'description' => 'Only in the answer to `create` — the one moment it is handed out.'],
                'state' => 'string',
                'initial_amount' => ['type' => 'integer', 'description' => 'Minor units of the shop\'s books.'],
                'balance' => ['type' => 'integer', 'description' => 'The sum of `entries`.'],
                'expires_at' => 'string|null',
                'owner_user_id' => 'integer|null',
                'recipient' => 'object',
                'source' => ['type' => 'string', 'description' => 'sold | issued | batch'],
                'order_id' => 'integer|null',
                'batch' => 'string|null',
                'rules' => 'object|null',
                'url' => ['type' => 'string', 'description' => 'The card\'s own public page.'],
                'entries' => 'array',
            ],
            'create' => fn (array $data) => $this->issue($data),
            'create_description' => 'Issue one card by hand. It is sent to `recipient_email` at once or on `deliver_at`. The answer carries the code — the only time the API hands it out.',
            'creatable' => [
                'amount' => ['type' => 'integer', 'description' => 'Minor units of the shop\'s books.'],
                'expires_at' => ['type' => 'string|null', 'description' => 'ISO 8601; absent = the shop\'s default, null = never.'],
                'recipient_name' => 'string|null',
                'recipient_email' => 'string|null',
                'sender_name' => 'string|null',
                'message' => 'string|null',
                'deliver_at' => 'string|null',
                'owner_user_id' => ['type' => 'integer|null', 'description' => 'Put it in this account: only it can spend the card.'],
                'rules' => ['type' => 'object|null', 'description' => '`categories` (ids, with their subtrees), `shipping` (bool), `max_percent`, `min_order` (minor units).'],
            ],
            'writable' => [
                'expires_at' => ['type' => 'string|null', 'description' => 'ISO 8601, or null for "never". Moving it forward revives an expired card.'],
                'recipient_name' => 'string|null',
                'recipient_email' => 'string|null',
                'note' => 'string|null',
            ],
            'update' => function (Card $card, array $data) {
                $allowed = array_intersect_key($data, array_flip(['expires_at', 'recipient_name', 'recipient_email', 'note']));

                if (array_key_exists('expires_at', $allowed)) {
                    $allowed['expires_at'] = $allowed['expires_at'] ? Carbon::parse($allowed['expires_at']) : null;
                }

                $card->update($allowed);
            },
            'actions' => [
                'adjust' => [
                    'description' => 'Correct the balance: a signed `amount` and a `reason` the customer will read in the history. Never below zero.',
                    'body' => [
                        'amount' => ['type' => 'integer', 'description' => 'Minor units; negative takes money off.'],
                        'reason' => 'string',
                    ],
                    'apply' => function (Card $card, array $data) {
                        $valid = Validator::make($data, ['amount' => 'required|integer|not_in:0', 'reason' => 'required|string|max:500'])->validate();

                        try {
                            app(Ledger::class)->adjust($card, (int) $valid['amount'], $valid['reason']);
                        } catch (\InvalidArgumentException) {
                            abort(422, __('A balance cannot go below zero.'));
                        }
                    },
                ],
                'activate' => [
                    'description' => 'A printed card handed over at the till: from now on it is money.',
                    'body' => [],
                    'apply' => fn (Card $card) => $card->status === Card::PENDING
                        ? $card->forceFill(['status' => Card::ACTIVE, 'activated_at' => now()])->save()
                        : null,
                ],
                'disable' => [
                    'description' => 'Switch it off. Its balance stays, and `enable` brings it back.',
                    'body' => [],
                    'apply' => fn (Card $card) => $card->forceFill(['status' => Card::DISABLED])->save(),
                ],
                'enable' => [
                    'description' => 'Switch it back on.',
                    'body' => [],
                    'apply' => fn (Card $card) => $card->forceFill(['status' => Card::ACTIVE, 'activated_at' => $card->activated_at ?? now()])->save(),
                ],
                'send' => [
                    'description' => 'Send (or send again) the letter to `recipient_email`.',
                    'body' => [],
                    'apply' => fn (Card $card) => $card->recipient_email ? app(Delivery::class)->send($card) : abort(422, __('Write the recipient\'s email first.')),
                ],
            ],
        ]);
    }

    protected function issue(array $data): Card
    {
        $valid = Validator::make($data, [
            'amount' => 'required|integer|min:1',
            'expires_at' => 'nullable|date',
            'recipient_name' => 'nullable|string|max:190',
            'recipient_email' => 'nullable|email|max:190',
            'sender_name' => 'nullable|string|max:190',
            'message' => 'nullable|string|max:500',
            'deliver_at' => 'nullable|date',
            'owner_user_id' => 'nullable|integer|exists:users,id',
            'rules' => 'nullable|array',
        ])->validate();

        $attributes = array_intersect_key($valid, array_flip(['recipient_name', 'recipient_email', 'sender_name', 'message', 'owner_user_id', 'rules']));
        $attributes['source'] = 'issued';

        if (array_key_exists('expires_at', $data)) {
            $attributes['expires_at'] = $valid['expires_at'] ? Carbon::parse($valid['expires_at'])->endOfDay() : null;
        }

        if (! empty($valid['deliver_at'])) {
            $attributes['deliver_at'] = Carbon::parse($valid['deliver_at']);
        }

        [$card, $code] = app(Issuer::class)->issue((int) $valid['amount'], $attributes);
        app(Delivery::class)->sendDue([$card]);

        // The plain code rides this one answer and is never stored in it.
        $card->setAttribute('plain_code', $code);

        return $card;
    }

    /** @return array<string, mixed> */
    protected function card(Card $card): array
    {
        return array_filter([
            'id' => $card->id,
            'last4' => $card->last4,
            'code' => $card->getAttribute('plain_code') ? \Torgash\Giftcards\Codes::format($card->getAttribute('plain_code')) : null,
            'state' => $card->state(),
            'initial_amount' => (int) $card->initial_amount,
            'balance' => (int) $card->balance,
            'expires_at' => $card->expires_at?->toIso8601String(),
            'owner_user_id' => $card->owner_user_id,
            'recipient' => [
                'name' => $card->recipient_name,
                'email' => $card->recipient_email,
                'sender' => $card->sender_name,
                'deliver_at' => $card->deliver_at?->toIso8601String(),
                'delivered_at' => $card->delivered_at?->toIso8601String(),
            ],
            'source' => $card->source,
            'order_id' => $card->order_id,
            'batch' => $card->batch,
            'rules' => $card->rules,
            'url' => $card->publicUrl(),
            'entries' => $card->entries()->limit(100)->get()->map(fn ($entry) => [
                'type' => $entry->type,
                'amount' => (int) $entry->amount,
                'order_id' => $entry->order_id,
                'note' => $entry->note,
                'by' => $entry->user_name,
                'at' => $entry->created_at?->toIso8601String(),
            ])->all(),
        ], fn ($value, $key) => $key !== 'code' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}

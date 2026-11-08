<?php

namespace Torgash\Enquiries;

use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use Carbon\CarbonInterface;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;

/**
 * What an enquiry holds about a person, for core's personal-data registry
 * (PLAN §49): who asked, how to reach them, what they wrote and sent.
 *
 * Erasing keeps the ROW — a return's ticket is where a refund hangs, and the
 * inbox's counts stay true — and empties everything that names the person:
 * the contacts, the answers, their side of the conversation, the photos.
 * The address and browser it was sent from also expire on their own, after
 * the shop's retention — the same as an order's.
 */
class EnquiryPersonalData
{
    public static function register(PersonalData $registry): void
    {
        $registry->register('enquiries', [
            'label' => 'Enquiries',
            'export' => fn (Subject $s) => $s->scope(Enquiry::query())->with('messages')->orderBy('id')->get()
                ->map(fn (Enquiry $enquiry) => [
                    'asked_at' => $enquiry->created_at?->toIso8601String(),
                    'kind' => $enquiry->kindLabel(),
                    'name' => $enquiry->name,
                    'email' => $enquiry->email,
                    'phone' => $enquiry->phone,
                    'ip_address' => $enquiry->ip_address,
                    'browser' => $enquiry->client,
                    'answers' => collect($enquiry->answers())->mapWithKeys(fn ($a) => [$a['label'] => $a['value']])->all(),
                    'conversation' => $enquiry->messages->map(fn (Message $m) => [
                        'from' => $m->is_staff ? 'shop' : 'customer',
                        'at' => $m->created_at?->toIso8601String(),
                        'text' => $m->body,
                    ])->all(),
                ])->all(),
            'erase' => function (Subject $s) {
                $count = 0;

                $s->scope(Enquiry::query())->with('messages')->each(function (Enquiry $enquiry) use (&$count) {
                    $enquiry->clearMediaCollection(Attachments::COLLECTION);

                    foreach ($enquiry->messages as $message) {
                        $message->clearMediaCollection(Attachments::COLLECTION);

                        if (! $message->is_staff) {
                            $message->forceFill(['body' => '—', 'author_name' => null, 'user_id' => null])->saveQuietly();
                        }
                    }

                    $fields = (array) $enquiry->fields;
                    $fields['values'] = [];

                    $enquiry->forceFill([
                        'name' => null, 'email' => null, 'phone' => null, 'user_id' => null,
                        'customer_ref' => null, 'page_url' => null, 'cart' => null, 'fields' => $fields,
                        'ip_address' => null, 'client' => null,
                    ])->saveQuietly();

                    $count++;
                });

                return $count;
            },
        ]);

        $registry->register('enquiry_ip', [
            'label' => 'IP address and browser of an enquiry',
            'default_days' => 180,
            'retention' => fn (CarbonInterface $before) => Enquiry::query()
                ->where('created_at', '<', $before)
                ->where(fn ($q) => $q->whereNotNull('ip_address')->orWhereNotNull('client'))
                ->update(['ip_address' => null, 'client' => null]),
        ]);
    }
}

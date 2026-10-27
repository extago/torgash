<?php

namespace Torgash\Rma;

use Torgash\Enquiries\Kinds;
use Torgash\Rma\Models\ReturnRequest;

/**
 * A return is a KIND of enquiry, and this is the whole of what makes it one.
 *
 * No inbox, no statuses, no manager field, no notification, no automations
 * trigger: the enquiries module already has all of it, and a second inbox is
 * how the quieter one goes unread (§29.2). What this module adds is the part an
 * enquiry cannot hold — which goods, and what happens to the money and the
 * stock.
 */
class Kind
{
    public const RETURN = 'return';

    public static function register(): void
    {
        Kinds::register(self::RETURN, [
            'label' => 'Return or exchange',
            'icon' => 'package-x',
            // Set by RequestReturn from the order the form was opened on, never
            // read from the request: a form must not be able to file a return
            // against somebody else's order.
            'subject' => 'order',
            'fields' => [
                [
                    'key' => 'resolution',
                    'type' => 'select',
                    'required' => true,
                    'label' => 'What would you like',
                    'options' => [
                        ReturnRequest::REFUND => 'Refund the money',
                        ReturnRequest::EXCHANGE => 'Exchange for the same thing',
                    ],
                ],
                [
                    'key' => 'reason',
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'What is wrong with it',
                ],
                // Prefilled from the order, and still asked: the address the
                // order was placed with is not always the one the person reads
                // today, and the answer is what the shop replies to.
                ['key' => 'name', 'type' => 'text', 'label' => 'Your name'],
                ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'type' => 'tel', 'label' => 'Phone'],
            ],
        ]);
    }
}

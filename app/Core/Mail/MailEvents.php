<?php

namespace App\Core\Mail;

/**
 * Every letter the shop can send (PLAN §41), declared by whoever sends it
 * through `core.mail.events` — asked lazily, since provider boot order is not
 * ours. An event is:
 *
 *   'checkout.order_placed' => [
 *       'label'    => 'Order confirmation',        // English, the owner's lang file
 *       'group'    => 'Orders',
 *       'audience' => 'customer' | 'shop',
 *       'required' => true,                         // cannot be switched off (a code)
 *       'subject'  => 'Order {order_number} confirmed',
 *       'heading'  => 'Thank you for your order!',
 *       'body'     => "Hi {customer_name}, …\n\n{order_details}",
 *       'button'   => ['label' => 'Track this order', 'url' => '{order_url}'],
 *       'tokens'   => fn (array $context) => ['order_number' => …],   // plain text
 *       'blocks'   => fn (array $context) => ['order_details' => '<table…'],  // HTML
 *       'help'     => ['order_number' => 'Order number', …],        // for the editor
 *       'sample'   => fn () => ['order' => Order::latest()->first()],  // preview
 *       'recipients' => fn () => [...],             // a shop letter's default addresses
 *       'editable' => false,                        // words are the sender's own (a campaign): not on Admin → Letters
 *   ]
 *
 * The default words are English SOURCE strings, translated in the reader's
 * language like every declared word (ADR-005) — so a shop that never opens
 * the editor still writes to a Russian customer in Russian.
 */
class MailEvents
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $events = [];

        foreach ((array) hook_filter('core.mail.events', []) as $key => $event) {
            if (is_array($event) && isset($event['subject'])) {
                $events[(string) $key] = $event + [
                    'audience' => 'customer',
                    'group' => 'Other',
                    'label' => $key,
                    'heading' => '',
                    'body' => '',
                    'button' => null,
                    'required' => false,
                    'editable' => true,
                ];
            }
        }

        return $events;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}

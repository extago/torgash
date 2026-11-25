<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The orders placed without signing in by people whose account has since
 * CONFIRMED that email or phone are theirs (GuestOrders does it from now on,
 * the moment a contact is confirmed; this does it once for what came before).
 *
 * Plain queries, not the classes: a migration runs before the module boots.
 * A phone matches by its last nine digits, as everywhere in checkout. An
 * account matched by two people's contacts (it cannot happen through the
 * unique indexes, but rows are rows) takes the order by email first. Staff
 * accounts are left out: a manager's test order is not their purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        $digits = "right(regexp_replace(coalesce(%s, ''), '\\D', '', 'g'), 9)";

        foreach ([
            'email' => "lower(o.email) = lower(u.email) AND u.email_verified_at IS NOT NULL AND coalesce(u.email, '') <> ''",
            'phone' => sprintf($digits, 'o.phone').' = '.sprintf($digits, 'u.phone')." AND u.phone_verified_at IS NOT NULL AND length(regexp_replace(coalesce(u.phone, ''), '\\D', '', 'g')) >= 9",
        ] as $how) {
            $pairs = DB::select("
                SELECT DISTINCT ON (o.id) o.id AS order_id, u.id AS user_id, coalesce(nullif(u.email, ''), u.phone) AS account
                FROM m_checkout_orders o
                JOIN users u ON $how
                WHERE o.user_id IS NULL
                  AND NOT EXISTS (SELECT 1 FROM model_has_roles r WHERE r.model_id = u.id)
                ORDER BY o.id, u.id
            ");

            foreach ($pairs as $pair) {
                DB::transaction(function () use ($pair) {
                    $updated = DB::table('m_checkout_orders')->where('id', $pair->order_id)->whereNull('user_id')
                        ->update(['user_id' => $pair->user_id]);

                    if ($updated) {
                        DB::table('m_checkout_order_logs')->insert([
                            'order_id' => $pair->order_id,
                            'user_id' => null,
                            'description' => "Attached to the account :account: it confirmed this order's contact",
                            'params' => json_encode(['account' => (string) $pair->account]),
                            'created_at' => now(),
                        ]);
                    }
                });
            }
        }
    }

    /** Nothing to undo honestly: which of these orders were guests' is not kept. */
    public function down(): void {}
};

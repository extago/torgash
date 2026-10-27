<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two more "no API integration needed" payment methods, same spirit as the
 * seeded set in 000202 (name + optional instructions, no gateway wiring).
 * Other engines ship the exact same kind of entry — a "discuss by phone"
 * payment method, manual/negotiated, with no processor attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('m_checkout_payment_methods')->insert([
            [
                'name' => '{"en":"Discuss with manager","ru":"Обсудить с менеджером"}',
                'instructions' => '{"en":"Our manager will contact you shortly to arrange payment.","ru":"Наш менеджер свяжется с вами в ближайшее время для согласования оплаты."}',
                'position' => 3,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => '{"en":"Pay with crypto","ru":"Оплата криптовалютой"}',
                'instructions' => '{"en":"Wallet address and payment instructions will be sent to your email after the order is placed.","ru":"Адрес кошелька и инструкции по оплате будут высланы на почту после оформления заказа."}',
                'position' => 4,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        // Match by name, not position — an admin may have reordered rows
        // since this ran, and position is not a stable identifier.
        DB::table('m_checkout_payment_methods')
            ->whereIn(DB::raw("name->>'en'"), ['Discuss with manager', 'Pay with crypto'])
            ->delete();
    }
};

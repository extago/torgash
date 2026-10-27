<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An order's history becomes a KEY plus its parameters, translated when read
 * (Models\OrderLog). The lines written before were finished English sentences
 * with the particulars glued in — "Status set to Открыт" — so they are taken
 * apart here by the very shapes that wrote them, most specific first. A line
 * none of them recognises is left exactly as it was: `__()` hands an unknown
 * key back unchanged, so it still reads as it always did.
 */
return new class extends Migration
{
    /** @return list<array{0: string, 1: string, 2: list<string>}> pattern, key, names of the captured parts */
    protected function shapes(): array
    {
        return [
            ['/^Status set to (.+)$/u', 'Status set to :status', ['status']],
            ['/^Delivery re-quoted: (.*) → (.*) \((.*)\), (.*)$/u', 'Delivery re-quoted: :from → :to (:cost), :city', ['from', 'to', 'cost', 'city']],
            ['/^Delivery priced: (.*) → (.*)$/u', 'Delivery priced: :from → :to', ['from', 'to']],
            ['/^Delivery: (.*) \((.*)\) → (.*) \((.*)\)$/u', 'Delivery: :from (:from_cost) → :to (:to_cost)', ['from', 'from_cost', 'to', 'to_cost']],
            ['/^Manager: (.*) → (.*)$/u', 'Manager: :from → :to', ['from', 'to']],
            ['/^Payment method: (.*) → (.*)$/u', 'Payment method: :from → :to', ['from', 'to']],
            ['/^Payment: (.*) → (.*) \((.*)\) — (.*)$/u', 'Payment: :from → :to (:amount) — :source', ['from', 'to', 'amount', 'source']],
            ['/^Payment: (.*) → (.*) \((.*)\)$/u', 'Payment: :from → :to (:amount)', ['from', 'to', 'amount']],
            ['/^Coupon removed: (.*)$/u', 'Coupon removed: :code', ['code']],
            ['/^Coupon (\S+) → (\S+) \(applied by hand although (.*)\)$/u', 'Coupon :from → :code (applied by hand although :why)', ['from', 'code', 'why']],
            ['/^Coupon (\S+) → (\S+)$/u', 'Coupon :from → :code', ['from', 'code']],
            ['/^Coupon (\S+) \(applied by hand although (.*)\)$/u', 'Coupon :code (applied by hand although :why)', ['code', 'why']],
            ['/^Coupon (\S+)$/u', 'Coupon :code', ['code']],
            ["/^Manager's discount removed$/u", 'Manager’s discount removed', []],
            ["/^Manager's discount: (.*) — (.*)$/u", 'Manager’s discount: :amount — :reason', ['amount', 'reason']],
            ['/^Auto-cancelled: unpaid for (\d+) h$/u', 'Auto-cancelled: unpaid for :hours h', ['hours']],
            ['/^Item added: (.*) \(now ×(\d+)\)$/u', 'Item added: :name (now ×:qty)', ['name', 'qty']],
            ['/^Item added: (.*) ×1$/u', 'Item added: :name ×1', ['name']],
            ['/^Item changed: (.*) ×(\d+) @ (.*) → ×(\d+) @ (.*)$/u', 'Item changed: :name ×:from_qty @ :from_price → ×:qty @ :price', ['name', 'from_qty', 'from_price', 'qty', 'price']],
            ['/^Build changed: (.*) — (.*) → (.*)$/u', 'Build changed: :name — :from → :to', ['name', 'from', 'to']],
            ['/^Item removed: (.*) ×(\d+) \(with (\d+) part\(s\) of it\)$/u', 'Item removed: :name ×:qty (with :parts part(s) of it)', ['name', 'qty', 'parts']],
            ['/^Item removed: (.*) ×(\d+)$/u', 'Item removed: :name ×:qty', ['name', 'qty']],
            ['/^Return #(\d+) received, (\d+) line\(s\) back into stock$/u', 'Return #:id received, :n line(s) back into stock', ['id', 'n']],
            ['/^Return #(\d+) received and written off$/u', 'Return #:id received and written off', ['id']],
            ['/^Exchange order (.*) created for return #(\d+)$/u', 'Exchange order :number created for return #:id', ['number', 'id']],
            // A rule or a gateway: "<who>: <from> → <to>". After every prefixed
            // shape above, since those read the same way.
            ['/^([^:]+): (.*) → (.*)$/u', ':rule: :from → :to', ['rule', 'from', 'to']],
            // A plain status move, last of all.
            ['/^(.*) → (.*)$/u', ':from → :to', ['from', 'to']],
        ];
    }

    public function up(): void
    {
        Schema::table('m_checkout_order_logs', function (Blueprint $table) {
            $table->jsonb('params')->nullable()->after('description');
        });

        $shapes = $this->shapes();

        // A status was written by its name in the writer's language. Any
        // language of a status's name finds the status, and the line then
        // carries the whole map — so it reads in the reader's language too.
        $statuses = [];
        foreach (DB::table('m_checkout_order_statuses')->get(['name']) as $status) {
            $map = (array) json_decode((string) $status->name, true);
            foreach ($map as $text) {
                if (is_string($text) && $text !== '') {
                    $statuses[$text] = $map;
                }
            }
        }
        $statusParams = ['status', 'from', 'to'];

        DB::table('m_checkout_order_logs')->orderBy('id')->chunkById(500, function ($rows) use ($shapes, $statuses, $statusParams) {
            foreach ($rows as $row) {
                foreach ($shapes as [$pattern, $key, $names]) {
                    if (! preg_match($pattern, (string) $row->description, $m)) {
                        continue;
                    }

                    $params = [];
                    foreach ($names as $i => $name) {
                        $value = $m[$i + 1];
                        // The one word these lines wrote in English on purpose.
                        $params[$name] = match (true) {
                            $value === 'unassigned' => ['__' => 'unassigned'],
                            // PaymentState's own English labels, as a worker or an
                            // English admin wrote them.
                            in_array($value, ['Awaiting payment', 'Authorized', 'Paid', 'Partially refunded', 'Refunded', 'Payment failed', 'Payment expired'], true) => ['__' => $value],
                            in_array($name, $statusParams, true) && isset($statuses[$value]) => $statuses[$value],
                            default => $value,
                        };
                    }

                    DB::table('m_checkout_order_logs')->where('id', $row->id)->update([
                        'description' => $key,
                        'params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
                    ]);

                    break;
                }
            }
        });
    }

    public function down(): void
    {
        // Back to finished sentences, in English, the way they used to be written.
        DB::table('m_checkout_order_logs')->whereNotNull('params')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $params = array_map(
                    fn ($v) => is_array($v) ? (string) ($v['__'] ?? pick_translation($v, 'en')) : (string) $v,
                    (array) json_decode((string) $row->params, true),
                );

                DB::table('m_checkout_order_logs')->where('id', $row->id)->update([
                    'description' => __((string) $row->description, $params, 'en'),
                ]);
            }
        });

        Schema::table('m_checkout_order_logs', function (Blueprint $table) {
            $table->dropColumn('params');
        });
    }
};

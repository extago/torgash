<?php

namespace Tests\Feature\Cdek;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Cdek\Models\Waybill;
use Torgash\Cdek\Waybills\Waybills;
use Torgash\Checkout\Models\Order;

/**
 * Registering an order with CDEK. The request shapes are the ones the test
 * server accepted (2026-10-21); the answers are copied from it — including
 * the part that made a waybill a row with a state: `POST orders` answers
 * "ACCEPTED" with no number, and the number comes later.
 */
class WaybillsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $settings = app(SettingsManager::class);
        $settings->set('torgash.cdek', 'account', 'acc');
        $settings->set('torgash.cdek', 'secure_password', 'sec');
        $settings->set('torgash.cdek', 'test_mode', false);
        $settings->set('torgash.cdek', 'shipment_point', 'NSK23');
        $settings->set('torgash.cdek', 'sender_city_code', 270);
        $settings->set('torgash.cdek', 'sender_address', 'ул. Складская, 1');
        $settings->set('torgash.cdek', 'sender_company', 'Torgash');
        $settings->set('torgash.cdek', 'sender_phone', '8 (900) 111-22-33');
    }

    public function test_a_point_order_starts_at_the_shops_office_and_names_the_customers_point(): void
    {
        $payload = $this->waybills()->payload($this->order(), ['weight_g' => 1500]);

        $this->assertSame(136, $payload['tariff_code']);
        $this->assertSame('NSK23', $payload['shipment_point']);
        $this->assertSame('MSK2', $payload['delivery_point']);
        $this->assertSame([['number' => '+79001234567']], $payload['recipient']['phones'], 'digits with the plus, as CDEK wants');
        $this->assertSame([['number' => '+79001112233']], $payload['sender']['phones'], 'a domestic 8… becomes +7…');
        $this->assertSame(1500, $payload['packages'][0]['weight']);
        $this->assertSame(['name' => 'Чайник', 'ware_key' => 'KT-1', 'cost' => 1000.0, 'payment' => ['value' => 0], 'weight' => 750, 'amount' => 2], $payload['packages'][0]['items'][0]);
        $this->assertArrayNotHasKey('delivery_recipient_cost', $payload, 'nothing collected unless asked');
    }

    public function test_a_courier_tariff_from_the_door_names_where_to_collect_and_where_to_deliver(): void
    {
        $order = $this->order(['delivery' => 'door', 'tariff_code' => 139, 'city_code' => 44, 'address' => 'ул. Тверская, 1']);

        $payload = $this->waybills()->payload($order, []);

        $this->assertSame(['code' => 270, 'address' => 'ул. Складская, 1'], $payload['from_location']);
        $this->assertSame(['code' => 44, 'address' => 'ул. Тверская, 1'], $payload['to_location']);
        $this->assertArrayNotHasKey('shipment_point', $payload);
    }

    public function test_cash_on_delivery_collects_what_is_still_owed_and_no_more(): void
    {
        // 2 × 1 000 ₽ goods + 300 ₽ delivery, 500 ₽ already paid: 1 800 ₽ owed.
        $order = $this->order(paid: 500_00);

        $payload = $this->waybills()->payload($order, ['cod' => '1']);
        $items = $payload['packages'][0]['items'];
        $collected = array_sum(array_map(fn ($item) => $item['payment']['value'] * $item['amount'], $items)) + $payload['delivery_recipient_cost']['value'];

        $this->assertEqualsWithDelta(1800.0, $collected, 0.01);
        $this->assertSame(1000.0, $items[0]['cost'], 'what the goods are worth is still declared in full');
    }

    public function test_the_form_collects_by_default_only_when_money_is_owed_and_not_awaited_online(): void
    {
        $this->assertTrue($this->waybills()->draft($this->order())['cod']);
        $this->assertFalse($this->waybills()->draft($this->order(paid: 2300_00))['cod'], 'paid');
        $this->assertFalse($this->waybills()->draft($this->order(status: 'awaiting_payment'))['cod'], 'a card payment that has not come yet');
    }

    public function test_what_is_missing_is_said_rather_than_sent(): void
    {
        app(SettingsManager::class)->set('torgash.cdek', 'shipment_point', '');

        $this->expectException(ValidationException::class);
        $this->waybills()->payload($this->order(), []);
    }

    public function test_a_waybill_is_accepted_without_a_number_and_numbered_later(): void
    {
        $this->fake([
            '*/v2/orders' => Http::response($this->accepted()),
            '*/v2/orders/28541c47-8cbf-43d2-ab58-a0eb5b37b18a' => Http::sequence()
                ->push($this->order_state('ACCEPTED', null, ['ACCEPTED']))
                ->push($this->order_state('SUCCESSFUL', '1110043280', ['CREATED', 'ACCEPTED'])),
        ]);
        $order = $this->order();

        $waybill = $this->waybills()->create($order, ['weight_g' => 1500]);

        $this->assertSame([Waybill::REGISTERING, null, false], [$waybill->state, $waybill->cdek_number, $waybill->isPrintable()]);
        $this->assertSame($order->order_number, $waybill->number);

        $this->waybills()->refresh($waybill);

        $this->assertSame([Waybill::ACTIVE, '1110043280', 'CREATED', true], [$waybill->state, $waybill->cdek_number, $waybill->status_code, $waybill->isPrintable()]);
        $this->assertStringEndsWith('order_id=1110043280', $waybill->trackingUrl());
    }

    public function test_a_waybill_refused_after_it_was_accepted_says_why_and_a_new_one_gets_a_new_number(): void
    {
        $this->fake([
            '*/v2/orders' => Http::response($this->accepted()),
            '*/v2/orders/28541c47-8cbf-43d2-ab58-a0eb5b37b18a' => Http::response($this->order_state('INVALID', null, ['ACCEPTED'], [['code' => 'x', 'message' => 'Pickup point is closed']])),
        ]);
        $order = $this->order();

        $waybill = $this->waybills()->create($order, []);

        $this->assertSame(Waybill::INVALID, $waybill->state);
        $this->assertSame(['Pickup point is closed'], $waybill->errors);
        $this->assertSame($order->order_number.'-2', $this->waybills()->payload($order, [])['number'], 'CDEK will not take one number twice');
    }

    public function test_one_open_waybill_per_order(): void
    {
        $this->fake([
            '*/v2/orders' => Http::response($this->accepted()),
            '*/v2/orders/28541c47-8cbf-43d2-ab58-a0eb5b37b18a' => Http::response($this->order_state('ACCEPTED', null, ['ACCEPTED'])),
        ]);
        $order = $this->order();
        $this->waybills()->create($order, []);

        $this->expectException(ValidationException::class);
        $this->waybills()->create($order, []);
    }

    public function test_it_can_be_cancelled_only_before_cdek_has_the_parcel(): void
    {
        $this->fake(['*/v2/orders/28541c47-8cbf-43d2-ab58-a0eb5b37b18a' => Http::response($this->accepted())]);
        $order = $this->order();

        $early = Waybill::create(['order_id' => $order->id, 'uuid' => '28541c47-8cbf-43d2-ab58-a0eb5b37b18a', 'number' => 'n1', 'tariff_code' => 136, 'state' => Waybill::ACTIVE, 'status_code' => 'CREATED', 'payload' => []]);
        $this->waybills()->cancel($early);
        $this->assertSame(Waybill::CANCELLED, $early->state);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), 'orders/28541c47-8cbf-43d2-ab58-a0eb5b37b18a'));

        $late = Waybill::create(['order_id' => $order->id, 'uuid' => '2bf269cf-1cf9-4599-b223-815f0cca8c7b', 'number' => 'n2', 'tariff_code' => 136, 'state' => Waybill::ACTIVE, 'status_code' => 'RECEIVED_AT_SHIPMENT_WAREHOUSE', 'payload' => []]);
        $this->expectException(ValidationException::class);
        $this->waybills()->cancel($late);
    }

    public function test_a_printed_form_is_waited_for_and_downloaded(): void
    {
        $this->fake([
            '*/v2/print/barcodes' => Http::response(['entity' => ['uuid' => 'p-1']]),
            '*/v2/print/barcodes/p-1' => Http::sequence()
                ->push(['entity' => ['uuid' => 'p-1', 'statuses' => [['code' => 'ACCEPTED']]]])
                ->push(['entity' => ['uuid' => 'p-1', 'url' => 'https://api.cdek.ru/v2/print/barcodes/p-1.pdf', 'statuses' => [['code' => 'READY']]]]),
            '*/p-1.pdf' => Http::response('%PDF-1.4 label'),
        ]);
        $waybill = Waybill::create(['order_id' => $this->order()->id, 'uuid' => '28541c47-8cbf-43d2-ab58-a0eb5b37b18a', 'number' => 'n', 'cdek_number' => '111', 'tariff_code' => 136, 'state' => Waybill::ACTIVE, 'payload' => []]);

        $pdf = $this->waybills()->withoutPrintPause()->pdf($waybill, 'label');

        $this->assertSame('%PDF-1.4 label', $pdf);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'print/barcodes') && $request['format'] === 'A6');
    }

    public function test_the_order_screen_carries_the_card_and_the_customer_sees_the_number(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/orders/'.$order->order_number)
            ->assertOk()
            ->assertSee('id="cdek-waybill-panel"', false)
            ->assertSee(route('admin.cdek.waybills.store', $order), false);

        Waybill::create(['order_id' => $order->id, 'uuid' => '96763d28-291b-465e-aa6d-0d063d99feae', 'number' => 'n', 'cdek_number' => '1110043280', 'tariff_code' => 136, 'state' => Waybill::ACTIVE, 'status_name' => 'Создан', 'payload' => []]);

        $this->get($order->customerUrl())->assertOk()->assertSee('1110043280');
    }

    public function test_an_order_sent_otherwise_has_no_card(): void
    {
        $order = $this->order();
        $order->update(['shipping_details' => ['carrier' => 'nova_poshta']]);
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/orders/'.$order->order_number)->assertOk()->assertDontSee('id="cdek-waybill-panel"', false);
    }

    protected function waybills(): Waybills
    {
        return app(Waybills::class);
    }

    /** @param  array<string, mixed>  $details */
    protected function order(array $details = [], int $paid = 0, string $status = 'open'): Order
    {
        return Order::create([
            'order_number' => 'TG-CD'.random_int(10000, 99999),
            'customer_name' => 'Иван Петров',
            'email' => 'ivan@example.test',
            'phone' => '+7 900 123-45-67',
            'currency_code' => 'RUB',
            'status' => $status,
            'subtotal' => 2000_00,
            'shipping_cost' => 300_00,
            'total' => 2300_00,
            'paid_total' => $paid,
            'items' => [['key' => 'k1', 'product_id' => 0, 'sku' => 'KT-1', 'name' => 'Чайник', 'qty' => 2, 'unit_price' => 1000_00, 'line_total' => 2000_00]],
            'shipping_details' => $details + [
                'carrier' => 'cdek', 'delivery' => 'point', 'tariff_code' => 136,
                'city_code' => 44, 'point_code' => 'MSK2', 'point_type' => 'PVZ',
            ],
        ]);
    }

    protected function fake(array $routes): void
    {
        Http::fake(['*/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600])] + $routes);
    }

    protected function accepted(): array
    {
        return ['entity' => ['uuid' => '28541c47-8cbf-43d2-ab58-a0eb5b37b18a'], 'requests' => [['type' => 'CREATE', 'state' => 'ACCEPTED']]];
    }

    /** An `orders/{uuid}` answer, shaped as the test server gives it. */
    protected function order_state(string $request, ?string $number, array $statuses, array $errors = []): array
    {
        return [
            'entity' => array_filter([
                'uuid' => '28541c47-8cbf-43d2-ab58-a0eb5b37b18a',
                'cdek_number' => $number,
                'statuses' => array_map(fn ($code, $i) => [
                    'code' => $code, 'name' => $code === 'CREATED' ? 'Создан' : 'Принят',
                    'date_time' => '2026-10-21T19:2'.(9 - $i).':00+0000',
                ], $statuses, array_keys($statuses)),
            ]),
            'requests' => [array_filter(['type' => 'CREATE', 'state' => $request, 'errors' => $errors])],
        ];
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'cdek-waybills@test')
            ?? User::create(['name' => 'Admin', 'email' => 'cdek-waybills@test', 'password' => bcrypt('x')]);
        $user->assignRole(Role::findOrCreate('admin'));

        return $user;
    }
}

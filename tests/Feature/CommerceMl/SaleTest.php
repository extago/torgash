<?php

namespace Tests\Feature\CommerceMl;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\CustomerRef;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\CommerceMl\Models\Exchange;
use Torgash\CommerceMl\Models\OrderSync;

/**
 * The other half of the exchange: orders going out, and the very short list of
 * what 1C may change when it answers.
 *
 * What is pinned here is mostly restraint again, in three shapes. The shop's
 * own condition decides which orders go at all, and a shop that has not stated
 * one sends nothing rather than everything. The document ADDS UP — delivery and
 * every added charge are lines, so an accountant never gets a total that does
 * not match its own contents. And 1C moves the status and settles the payment;
 * it does not rewrite what was bought, however different the document it holds.
 */
class SaleTest extends TestCase
{
    protected string $token;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = ApiToken::issue(User::role('admin')->firstOrFail(), '1C');

        $this->product = Product::create([
            'sku' => 'SALE-1',
            'slug' => 'sale-1',
            'name' => ['en' => 'Drill'],
            'price' => 100_00,
            'status' => 'active',
            'external_id' => 'p-drill',
        ]);

        $this->set([
            'sale_enabled' => true,
            'sale_statuses' => ['processing'],
            'sale_paid' => false,
            'sale_match' => 'any',
            'sale_from' => '',
            'sale_status_shipped' => 'shipped',
            'sale_guest_domain' => '',
            'sale_delivery_guid' => 'svc-delivery',
            'attr_shipped' => "Дата отгрузки по 1С",
            'attr_paid' => "Дата оплаты по 1С",
            'content_locale' => 'en',
            'batch_size' => 200,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (Exchange::pluck('path') as $path) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------- the queue

    /** An unanswered question is not "send everything". */
    public function test_a_shop_that_has_stated_no_condition_sends_nothing(): void
    {
        $this->set(['sale_statuses' => [], 'sale_paid' => false]);
        $this->order(['status' => 'processing']);

        $this->assertStringNotContainsString('Документ', $this->askForOrders());
    }

    public function test_only_orders_the_shop_nominated_are_handed_over(): void
    {
        $this->order(['order_number' => 'TG-OPEN', 'status' => 'open']);
        $this->order(['order_number' => 'TG-PROC', 'status' => 'processing']);

        $xml = $this->askForOrders();

        $this->assertStringContainsString('TG-PROC', $xml);
        $this->assertStringNotContainsString('TG-OPEN', $xml);
    }

    /** Payment is the second axis, and a shop may qualify on it instead. */
    public function test_a_paid_order_qualifies_on_the_other_axis(): void
    {
        $this->set(['sale_statuses' => [], 'sale_paid' => true]);
        $this->order(['order_number' => 'TG-PAID', 'status' => 'open', 'payment_state' => PaymentState::PAID]);

        $this->assertStringContainsString('TG-PAID', $this->askForOrders());
    }

    // ----------------------------------------------------------- the document

    public function test_the_document_adds_up_and_names_the_customer(): void
    {
        $order = $this->order([
            'status' => 'processing',
            'shipping_cost' => 50_00,
            'shipping_method_name' => 'Courier',
            'discount_amount' => 10_00,
            'total' => 240_00,
        ]);

        $xml = simplexml_load_string($this->askForOrders());
        $document = $xml->{'Документ'};

        $this->assertSame($order->order_number, (string) $document->{'Номер'});
        $this->assertSame('240.00', (string) $document->{'Сумма'});

        // Two lines: the goods, and delivery as the service it is. Their sum,
        // less the discount, is the total — by construction, not by luck.
        $lines = $document->{'Товары'}->{'Товар'};
        $this->assertCount(2, $lines);
        $this->assertSame('p-drill', (string) $lines[0]->{'Ид'});
        $this->assertSame('200.00', (string) $lines[0]->{'Сумма'});
        $this->assertSame('svc-delivery', (string) $lines[1]->{'Ид'});
        $this->assertSame('50.00', (string) $lines[1]->{'Сумма'});

        $sum = array_sum(array_map(fn ($line) => (float) (string) $line->{'Сумма'}, iterator_to_array($lines, false)));
        $discount = (float) (string) $document->{'Скидки'}->{'Скидка'}->{'Сумма'};
        $this->assertSame(240.0, round($sum - $discount, 2));

        // The buyer is named by their own stable reference, not by a row id.
        $buyer = $document->{'Контрагенты'}->{'Контрагент'};
        $this->assertSame($order->customer_ref ?: app(CustomerRef::class)->for($order), (string) $buyer->{'Ид'});
        $this->assertSame('Покупатель', (string) $buyer->{'Роль'});
    }

    /**
     * An automatic promotion takes money OFF, and CommerceML has an element for
     * that. It used to leave as a `<Товар>` priced at −20.00, because this
     * module exports every extra-totals line as a service and a discount is one
     * of those lines: a document some 1C configurations refuse outright, and
     * none of them read as a discount.
     */
    public function test_a_negative_extra_line_leaves_as_a_discount_not_a_negative_priced_item(): void
    {
        $order = $this->order([
            'status' => 'processing',
            'shipping_cost' => 50_00,
            'shipping_method_name' => 'Courier',
            'discount_amount' => 10_00,
            'total' => 220_00,
        ]);

        // What a cart-zone promotion leaves on a placed order.
        \Illuminate\Support\Facades\DB::table('m_promotions_order_promotions')->insert([
            'order_id' => $order->id,
            'promotion_id' => null,
            'name' => 'Black Friday',
            'amount' => -20_00,
        ]);

        $xml = simplexml_load_string($this->askForOrders());
        $document = $xml->{'Документ'};

        $lines = iterator_to_array($document->{'Товары'}->{'Товар'}, false);
        foreach ($lines as $line) {
            $this->assertGreaterThan(0, (float) (string) $line->{'Сумма'}, 'no line may be priced below zero');
        }

        $discounts = iterator_to_array($document->{'Скидки'}->{'Скидка'}, false);
        $this->assertCount(2, $discounts, 'the coupon and the promotion, each under its own name');
        $this->assertSame('Black Friday', (string) $discounts[1]->{'Наименование'});
        $this->assertSame('20.00', (string) $discounts[1]->{'Сумма'});
        $this->assertSame('Нет', (string) $discounts[1]->{'УчтеноВСумме'});

        // Still adds up: lines, less every discount, is the total.
        $sum = array_sum(array_map(fn ($line) => (float) (string) $line->{'Сумма'}, $lines));
        $off = array_sum(array_map(fn ($d) => (float) (string) $d->{'Сумма'}, $discounts));
        $this->assertSame(220.0, round($sum - $off, 2));
    }

    /**
     * A shop with courier delivery in 1C and a parcel carrier outside it needs
     * the identifier per method, not per shop.
     */
    public function test_a_delivery_method_can_carry_its_own_1c_service(): void
    {
        $this->set(['sale_service_guids' => ['7' => 'svc-courier']]);

        $this->order([
            'status' => 'processing',
            'shipping_cost' => 50_00,
            'shipping_method_name' => 'Courier',
            'shipping_details' => ['shipping_method_id' => 7],
        ]);

        $xml = simplexml_load_string($this->askForOrders());
        $lines = iterator_to_array($xml->{'Документ'}->{'Товары'}->{'Товар'}, false);

        $this->assertSame('svc-courier', (string) end($lines)->{'Ид'});
    }

    /**
     * A one-click order has no email, and 1C's counterparty card wants one.
     * Made up on the way out, and stored nowhere.
     */
    public function test_a_buyer_with_no_email_gets_one_invented_for_1c_only(): void
    {
        $this->set(['sale_guest_domain' => 'orders.example.com']);
        $order = $this->order(['status' => 'processing', 'email' => '', 'phone' => '+380 67 123-45-67']);

        $xml = simplexml_load_string($this->askForOrders());
        $contacts = $xml->{'Документ'}->{'Контрагенты'}->{'Контрагент'}->{'Контакты'};
        $emails = [];

        foreach ($contacts->{'ЗначениеРеквизита'} as $contact) {
            $emails[(string) $contact->{'Наименование'}] = (string) $contact->{'Значение'};
        }

        $this->assertSame($order->customer_ref.'@orders.example.com', $emails['Почта']);
        $this->assertSame('', $order->fresh()->email);
    }

    /** A company's order goes to 1C as a legal counterparty: official name and tax number. */
    public function test_a_company_goes_as_a_legal_counterparty(): void
    {
        $this->order(['status' => 'processing', 'company_name' => 'Acme LLC', 'company_tax_id' => '12345678']);

        $buyer = simplexml_load_string($this->askForOrders())->{'Документ'}->{'Контрагенты'}->{'Контрагент'};

        $this->assertSame('Acme LLC', (string) $buyer->{'Наименование'});
        $this->assertSame('Acme LLC', (string) $buyer->{'ОфициальноеНаименование'});
        $this->assertSame('12345678', (string) $buyer->{'ИНН'});
        $this->assertSame('', (string) $buyer->{'ПолноеНаименование'});
    }

    /** The same buyer, twice, is one counterparty — with no account and no email. */
    public function test_two_orders_from_one_phone_carry_one_reference(): void
    {
        $refs = app(CustomerRef::class);

        $this->assertSame(
            $refs->for(['phone' => '+380 (67) 123-45-67']),
            $refs->for(['phone' => '0671234567']),
        );

        $this->assertNotSame(
            $refs->for(['phone' => '0671234567']),
            $refs->for(['phone' => '0671234568']),
        );
    }

    // ------------------------------------------------------------- the ledger

    /** Offered is not delivered: 1C says `success` when it has actually taken them. */
    public function test_an_order_is_offered_again_until_1c_confirms_it(): void
    {
        $order = $this->order(['status' => 'processing']);

        $this->assertStringContainsString($order->order_number, $this->askForOrders());
        $this->assertNull(OrderSync::firstOrFail()->confirmed_at);

        // A second ask with no confirmation in between: still owed.
        $this->assertStringContainsString($order->order_number, $this->askForOrders());

        $this->confirm();
        $this->assertStringNotContainsString($order->order_number, $this->askForOrders());
    }

    public function test_an_order_that_changes_goes_again(): void
    {
        $order = $this->order(['status' => 'processing']);
        $this->askForOrders();
        $this->confirm();

        $order->update(['customer_name' => 'Somebody Else']);

        $this->assertStringContainsString('Somebody Else', $this->askForOrders());
    }

    // ---------------------------------------------------------- coming back

    public function test_a_shipment_in_1c_moves_the_status_and_a_payment_settles_the_order(): void
    {
        $order = $this->order(['status' => 'processing']);

        $this->answer($order->order_number, [
            'Дата отгрузки по 1С' => '2026-09-05',
            'Дата оплаты по 1С' => '2026-09-04',
        ]);

        $order->refresh();

        $this->assertSame('shipped', $order->status);
        $this->assertSame(PaymentState::PAID, $order->payment_state);
        $this->assertSame($order->total, $order->paid_total);
        $this->assertNotNull($order->paid_at);
    }

    /**
     * An attribute that is present but says nothing is not an event — otherwise
     * the first exchange marks every order shipped.
     */
    public function test_an_empty_or_negative_attribute_changes_nothing(): void
    {
        $order = $this->order(['status' => 'processing']);

        $this->answer($order->order_number, ['Дата отгрузки по 1С' => '', 'Дата оплаты по 1С' => 'Нет']);

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(PaymentState::PENDING, $order->fresh()->payment_state);
    }

    /** The rule with the most money behind it. */
    public function test_1c_may_not_rewrite_what_was_bought(): void
    {
        $order = $this->order(['status' => 'processing']);

        $this->answer($order->order_number, ['Дата отгрузки по 1С' => '2026-09-05'], items: [
            ['name' => 'Drill', 'quantity' => 1, 'price' => '5.00', 'total' => '5.00'],
        ]);

        $order->refresh();

        $this->assertSame(200_00, $order->total);
        $this->assertSame(2, $order->items[0]['qty']);
        $this->assertSame(100_00, $order->items[0]['unit_price']);
    }

    /** An order 1C invented, or one from another shop, is skipped rather than guessed at. */
    public function test_a_document_about_an_unknown_order_is_skipped(): void
    {
        $this->answer('TG-NOT-OURS', ['Дата отгрузки по 1С' => '2026-09-05']);

        $this->assertSame(0, OrderSync::count());
    }

    // --------------------------------------------------------------- helpers

    /** @param array<string, mixed> $values */
    protected function set(array $values): void
    {
        $settings = app(SettingsManager::class);

        foreach ($values as $key => $value) {
            $settings->set('torgash.commerceml', $key, $value);
        }
    }

    /** @param array<string, mixed> $overrides */
    protected function order(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'TG-SALE'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'phone' => '+380671112233',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => shop_currency(),
            'subtotal' => 200_00,
            'shipping_cost' => 0,
            'total' => 200_00,
            'status' => 'open',
            'stock_deducted' => false,
            'customer_ref' => null,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => 'SALE-1',
                'name' => 'Drill',
                'qty' => 2,
                'unit_price' => 100_00,
                'line_total' => 200_00,
            ]],
        ], $overrides));

        if (! $order->customer_ref) {
            $order->forceFill(['customer_ref' => app(CustomerRef::class)->for($order)])->save();
        }

        return $order->fresh();
    }

    protected function exchange(string $query, ?string $body = null)
    {
        return $this->call(
            $body === null ? 'GET' : 'POST',
            'http://localhost/commerceml?'.$query,
            server: ['PHP_AUTH_USER' => 'anything', 'PHP_AUTH_PW' => $this->token],
            content: $body,
        );
    }

    /** checkauth, then ask for the orders. */
    protected function askForOrders(): string
    {
        $this->open();

        return $this->exchange('type=sale&mode=query')->getContent();
    }

    protected function confirm(): void
    {
        $this->exchange('type=sale&mode=success')->assertSee('success');
    }

    protected function open(): void
    {
        $lines = explode("\n", trim($this->exchange('type=sale&mode=checkauth')->getContent()));

        $this->withUnencryptedCookie($lines[1], $lines[2]);
    }

    /**
     * 1C answering about one order: upload a document and import it, which is
     * the same file+import pair the catalogue half uses.
     *
     * @param  array<string, string>  $attributes
     * @param  array<int, array<string, string>>|null  $items
     */
    protected function answer(string $number, array $attributes, ?array $items = null): void
    {
        $this->open();

        $bag = '';

        foreach ($attributes as $name => $value) {
            $bag .= "<ЗначениеРеквизита><Наименование>{$name}</Наименование><Значение>{$value}</Значение></ЗначениеРеквизита>";
        }

        $goods = '';

        foreach ($items ?? [] as $item) {
            $goods .= "<Товар><Ид>p-drill</Ид><Наименование>{$item['name']}</Наименование>"
                ."<ЦенаЗаЕдиницу>{$item['price']}</ЦенаЗаЕдиницу><Количество>{$item['quantity']}</Количество>"
                ."<Сумма>{$item['total']}</Сумма></Товар>";
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<КоммерческаяИнформация ВерсияСхемы="2.05">'
            ."<Документ><Ид>{$number}</Ид><Номер>{$number}</Номер>"
            .($goods ? "<Товары>{$goods}</Товары>" : '')
            ."<ЗначенияРеквизитов>{$bag}</ЗначенияРеквизитов></Документ>"
            .'</КоммерческаяИнформация>';

        $this->exchange('type=sale&mode=file&filename=orders.xml', $xml)->assertSee('success');
        $this->exchange('type=sale&mode=import&filename=orders.xml')->assertSee('success');
    }
}

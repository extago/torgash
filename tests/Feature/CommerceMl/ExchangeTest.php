<?php

namespace Tests\Feature\CommerceMl;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\CommerceMl\Models\Exchange;
use Torgash\CommerceMl\Models\Link;

/**
 * The 1C exchange, from checkauth to a product on the shelf.
 *
 * Almost everything pinned here is about NOT writing. An exchange runs
 * unattended, nightly, against a shop somebody spent a month arranging, and the
 * failure that matters is not "it stopped" — that gets noticed — but "it quietly
 * replaced the copy, the photographs and the prices with an accountant's
 * spreadsheet". So: an unmatched price type writes no price, an undecided
 * characteristic reaches no filter, a field pinned to first import stays put,
 * and a file name that climbs out of its directory is refused.
 */
class ExchangeTest extends TestCase
{
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = ApiToken::issue(User::role('admin')->firstOrFail(), '1C');

        // Deliberately the loudest settings, so a test that passes says the
        // guard held rather than that the feature was off.
        $this->set([
            'import_strategy' => 'all',
            'create_categories' => true,
            'create_features' => false,
            'import_offers' => true,
            'content_locale' => 'en',
            'fields_new_only' => ['name'],
            'category_change' => 'add',
            'image_change' => 'new_only',
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

    /** @param array<string, mixed> $values */
    protected function set(array $values): void
    {
        $settings = app(\App\Core\Settings\SettingsManager::class);

        foreach ($values as $key => $value) {
            $settings->set('torgash.commerceml', $key, $value);
        }
    }

    // --------------------------------------------------------------- protocol

    protected function exchange(string $query, ?string $body = null, ?string $password = null)
    {
        return $this->call(
            $body === null ? 'GET' : 'POST',
            'http://localhost/commerceml?'.$query,
            server: [
                'PHP_AUTH_USER' => 'anything',
                'PHP_AUTH_PW' => $password ?? $this->token,
            ],
            content: $body,
        );
    }

    /** The whole handshake, leaving an open exchange whose cookie is set. */
    protected function open(): string
    {
        $lines = explode("\n", trim($this->exchange('type=catalog&mode=checkauth')->getContent()));

        $this->withUnencryptedCookie($lines[1], $lines[2]);

        return $lines[2];
    }

    protected function send(string $name, string $body): void
    {
        $this->exchange('type=catalog&mode=file&filename='.urlencode($name), $body)
            ->assertSee('success');
    }

    protected function import(string $name): string
    {
        return trim($this->exchange('type=catalog&mode=import&filename='.urlencode($name))->getContent());
    }

    // ------------------------------------------------------------------- auth

    public function test_a_call_with_no_credentials_is_asked_for_them(): void
    {
        $this->get('http://localhost/commerceml?type=catalog&mode=checkauth')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Basic realm="CommerceML"');
    }

    /** A read-only token is not a key to the catalogue. */
    public function test_a_token_that_may_not_write_is_refused(): void
    {
        [, $readOnly] = ApiToken::issue(User::role('admin')->firstOrFail(), 'Feed', ['read']);

        $this->exchange('type=catalog&mode=checkauth', null, $readOnly)->assertStatus(401);
        $this->exchange('type=catalog&mode=checkauth', null, 'tg_nonsense')->assertStatus(401);
    }

    public function test_the_handshake_answers_what_the_protocol_asks_for(): void
    {
        $lines = explode("\n", trim($this->exchange('type=catalog&mode=checkauth')->getContent()));

        $this->assertSame('success', $lines[0]);
        $this->assertNotEmpty($lines[1]);            // the cookie's name
        $this->assertNotEmpty($lines[2]);            // its value, which is the exchange
        $this->assertSame(1, Exchange::count());

        $this->withUnencryptedCookie($lines[1], $lines[2]);

        $init = $this->exchange('type=catalog&mode=init')->getContent();

        $this->assertMatchesRegularExpression('~^zip=(yes|no)\nfile_limit=\d+$~', trim($init));
    }

    /** A file bigger than the announced limit arrives in pieces, and they are APPENDED. */
    public function test_the_pieces_of_one_file_are_joined_not_overwritten(): void
    {
        $this->open();

        $this->send('import.xml', '<?xml version="1.0"?>'."\n");
        $this->send('import.xml', '<КоммерческаяИнформация/>');

        $exchange = Exchange::firstOrFail();

        $this->assertStringEndsWith('<КоммерческаяИнформация/>', file_get_contents($exchange->file('import.xml')));
        $this->assertStringStartsWith('<?xml', file_get_contents($exchange->file('import.xml')));
    }

    /** The one genuinely dangerous string in the module. */
    public function test_a_file_name_that_climbs_out_of_its_directory_is_refused(): void
    {
        $this->open();

        foreach (['../escaped.xml', 'a/../../escaped.xml', '/etc/passwd', 'C:\\windows\\x'] as $name) {
            $this->exchange('type=catalog&mode=file&filename='.urlencode($name), 'x')
                ->assertSee('failure');
        }

        $this->assertFileDoesNotExist(dirname(Exchange::firstOrFail()->path).DIRECTORY_SEPARATOR.'escaped.xml');
    }

    // -------------------------------------------------------------- catalogue

    public function test_a_catalogue_arrives_as_categories_and_products(): void
    {
        $this->open();
        $this->send('import.xml', $this->catalogueXml());

        $this->assertStringContainsString('success', $this->import('import.xml'));

        $category = Category::where('external_id', 'g-laptops')->firstOrFail();
        $this->assertSame('Ноутбуки', $category->name['en']);

        $product = Product::where('external_id', 'p-1')->firstOrFail();
        $this->assertSame('ST-9001', $product->sku);
        $this->assertSame('Ноутбук Alpha 15', $product->name['en']);
        // 1C writes kilograms and centimetres; the database holds whole grams
        // and whole millimetres, always.
        $this->assertSame(1600, $product->weight);
        $this->assertSame(350, $product->length);
        $this->assertTrue($product->categories->contains($category));

        // Hidden, because a product from 1C has no photograph, no storefront
        // copy and no category of the shop's own until somebody looks at it.
        $this->assertSame('hidden', $product->status);
    }

    /** The vocabulary is RECORDED and waits for a person. */
    public function test_a_characteristic_reaches_the_queue_and_not_the_catalogue(): void
    {
        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');

        $property = Link::where(['kind' => Link::KIND_FEATURE, 'guid' => 'f-colour'])->firstOrFail();

        $this->assertSame(Link::ASK, $property->decision);
        $this->assertSame('Цвет', $property->name);

        // Its variants are queued under it, keyed by property AND value: 1C's
        // value ids are only unique inside their own property.
        $variant = Link::where([
            'kind' => Link::KIND_FEATURE_VALUE,
            'guid' => 'f-colour#v-black',
        ])->firstOrFail();

        $this->assertSame('f-colour', $variant->parent_guid);

        // And nothing was written onto the product: an undecided vocabulary
        // does not reach the filter panel by accident.
        $product = Product::where('external_id', 'p-1')->firstOrFail();
        $this->assertSame(0, $product->featureAssignments()->count());
    }

    /** 1C says a product is gone: disabled, never deleted. */
    public function test_a_deletion_disables_rather_than_deletes(): void
    {
        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');

        $this->send('import2.xml', $this->catalogueXml(deleted: true));
        $this->import('import2.xml');

        $product = Product::withTrashed()->where('external_id', 'p-1')->firstOrFail();

        $this->assertSame('disabled', $product->status);
        $this->assertNull($product->deleted_at);
    }

    /** What the shop rewrote for its storefront is not overwritten tonight. */
    public function test_a_field_pinned_to_the_first_import_stays_put(): void
    {
        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');

        $product = Product::where('external_id', 'p-1')->firstOrFail();
        $product->forceFill(['name' => ['en' => 'Alpha 15 — the one we actually sell']])->save();

        $this->send('import2.xml', $this->catalogueXml());
        $this->import('import2.xml');

        // `name` is in fields_new_only; `weight` deliberately is not, because a
        // weight is a fact about the object rather than an editorial decision.
        $this->assertSame('Alpha 15 — the one we actually sell', $product->fresh()->name['en']);
        $this->assertSame(1600, $product->fresh()->weight);
    }

    // ----------------------------------------------------------------- offers

    /** The rule that stops a shop publishing its purchase price. */
    public function test_an_unmatched_price_type_writes_no_price(): void
    {
        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');

        $this->send('offers.xml', $this->offersXml());
        $message = $this->import('offers.xml');

        $product = Product::where('external_id', 'p-1')->firstOrFail();

        $this->assertSame(0, $product->price);
        // ...and the shop is told, in the words 1C prints to whoever ran it.
        $this->assertStringContainsString('not matched', $message);

        // The type is in the queue, named, so a person can recognise it.
        $link = Link::where(['kind' => Link::KIND_PRICE_TYPE, 'guid' => 't-retail'])->firstOrFail();
        $this->assertSame('Розничная', $link->name);
    }

    /** Matched to the base price, the money goes through the shop's own arithmetic. */
    public function test_a_matched_price_type_writes_the_price_and_the_stock(): void
    {
        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');

        $this->send('offers.xml', $this->offersXml());
        $this->import('offers.xml');

        Link::where(['kind' => Link::KIND_PRICE_TYPE, 'guid' => 't-retail'])->firstOrFail()
            ->forceFill(['decision' => Link::LINK, 'local_key' => 'base'])->save();

        $this->send('offers2.xml', $this->offersXml());
        $this->import('offers2.xml');

        $product = Product::where('external_id', 'p-1')->firstOrFail();

        // 1C quoted hryvnia, and the money went through the shop's own
        // arithmetic rather than into the column: the TYPED number is kept
        // exactly as sent (major units on the wire, minor units stored) with
        // the currency beside it, and the books amount is DERIVED. Writing
        // `price` directly would have put hryvnia in a dollar column with
        // nothing to say so — and a later rate change would not fix it.
        $this->assertSame('UAH', $product->price_currency);
        $this->assertSame(2499900, $product->price_original);
        $this->assertGreaterThan(0, $product->price);
        $this->assertNotSame(2499900, $product->price);

        $this->assertSame(12, $product->stock);
    }

    /** 1C's minus one is its own reservation, not a negative shelf. */
    public function test_a_negative_quantity_is_no_stock_rather_than_less_than_none(): void
    {
        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');
        $this->send('offers.xml', $this->offersXml(quantity: '-3'));
        $this->import('offers.xml');

        $this->assertSame(0, Product::where('external_id', 'p-1')->firstOrFail()->stock);
    }

    /** "Receive and record, change nothing" — the setting a shop should start with. */
    public function test_the_do_nothing_strategy_still_fills_the_queue(): void
    {
        $this->set(['import_strategy' => 'none']);

        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');

        $this->assertSame(0, Product::where('external_id', 'p-1')->count());
        // The classifier is still read: that is what makes a first exchange
        // useful before it is allowed to write anything.
        $this->assertSame(1, Link::where('kind', Link::KIND_FEATURE)->count());
        $this->assertSame(1, Category::where('external_id', 'g-laptops')->count());
    }

    /** An existing catalogue acquires its identifiers on the first exchange. */
    public function test_a_product_that_already_exists_is_recognised_by_its_article_number(): void
    {
        $existing = Product::create([
            'sku' => 'ST-9001', 'name' => ['en' => 'Ours'], 'slug' => 'ours',
            'price' => 100, 'stock' => 1, 'status' => 'active',
        ]);

        $this->open();
        $this->send('import.xml', $this->catalogueXml());
        $this->import('import.xml');

        $existing->refresh();

        $this->assertSame('p-1', $existing->external_id);
        // Recognised, not replaced: it keeps the status the shop gave it.
        $this->assertSame('active', $existing->status);
        $this->assertSame(1, Product::where('sku', 'ST-9001')->count());
    }

    // --------------------------------------------------------------- fixtures

    protected function catalogueXml(bool $deleted = false): string
    {
        $status = $deleted ? ' Статус="Удален"' : '';

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <КоммерческаяИнформация ВерсияСхемы="2.05" ДатаФормирования="2026-09-05">
          <Классификатор>
            <Ид>cls-1</Ид>
            <Наименование>Каталог товаров</Наименование>
            <Группы>
              <Группа>
                <Ид>g-laptops</Ид>
                <Наименование>Ноутбуки</Наименование>
              </Группа>
            </Группы>
            <Свойства>
              <Свойство>
                <Ид>f-colour</Ид>
                <Наименование>Цвет</Наименование>
                <ТипЗначений>Справочник</ТипЗначений>
                <ВариантыЗначений>
                  <Справочник>
                    <ИдЗначения>v-black</ИдЗначения>
                    <Значение>Чёрный</Значение>
                  </Справочник>
                </ВариантыЗначений>
              </Свойство>
            </Свойства>
          </Классификатор>
          <Каталог>
            <Ид>cat-1</Ид>
            <ИдКлассификатора>cls-1</ИдКлассификатора>
            <Товары>
              <Товар{$status}>
                <Ид>p-1</Ид>
                <Артикул>ST-9001</Артикул>
                <Наименование>Ноутбук Alpha 15</Наименование>
                <Группы>
                  <Ид>g-laptops</Ид>
                </Группы>
                <ЗначенияРеквизитов>
                  <ЗначениеРеквизита>
                    <Наименование>ОписаниеВФорматеHTML</Наименование>
                    <Значение>&lt;p&gt;Тонкий и лёгкий&lt;/p&gt;</Значение>
                  </ЗначениеРеквизита>
                  <ЗначениеРеквизита>
                    <Наименование>Вес</Наименование>
                    <Значение>1,6</Значение>
                  </ЗначениеРеквизита>
                  <ЗначениеРеквизита>
                    <Наименование>Длина</Наименование>
                    <Значение>35</Значение>
                  </ЗначениеРеквизита>
                </ЗначенияРеквизитов>
                <ЗначенияСвойств>
                  <ЗначенияСвойства>
                    <Ид>f-colour</Ид>
                    <Значение>v-black</Значение>
                  </ЗначенияСвойства>
                </ЗначенияСвойств>
              </Товар>
            </Товары>
          </Каталог>
        </КоммерческаяИнформация>
        XML;
    }

    protected function offersXml(string $quantity = '12'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <КоммерческаяИнформация ВерсияСхемы="2.05" ДатаФормирования="2026-09-05">
          <ПакетПредложений>
            <Ид>pkg-1</Ид>
            <ТипыЦен>
              <ТипЦены>
                <Ид>t-retail</Ид>
                <Наименование>Розничная</Наименование>
                <Валюта>UAH</Валюта>
              </ТипЦены>
            </ТипыЦен>
            <Предложения>
              <Предложение>
                <Ид>p-1</Ид>
                <Артикул>ST-9001</Артикул>
                <Цены>
                  <Цена>
                    <ИдТипаЦены>t-retail</ИдТипаЦены>
                    <ЦенаЗаЕдиницу>24999,00</ЦенаЗаЕдиницу>
                    <Валюта>UAH</Валюта>
                  </Цена>
                </Цены>
                <Количество>{$quantity}</Количество>
              </Предложение>
            </Предложения>
          </ПакетПредложений>
        </КоммерческаяИнформация>
        XML;
    }
}

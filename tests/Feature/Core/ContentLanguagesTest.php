<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Feature;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * An admin form writes ONE language — the one chosen at the top — and keeps
 * every other: a form that posted `name_en` + `name_ru` wiped the third
 * language of any shop that had one on every save.
 */
class ContentLanguagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    public function test_the_helper_writes_one_language_and_blank_removes_it(): void
    {
        $this->assertSame(['en' => 'A', 'uk' => 'Б'], put_translation(['en' => 'A', 'uk' => 'В'], ' Б ', 'uk'));
        $this->assertSame(['en' => 'A'], put_translation(['en' => 'A', 'uk' => 'В'], '', 'uk'));
        $this->assertSame('В', pick_translation('{"en":"A","uk":"В"}', 'uk'));
        $this->assertSame('A', pick_translation(['en' => 'A', 'uk' => ''], 'uk'));
    }

    public function test_shipping_payment_and_status_forms_keep_the_languages_they_do_not_show(): void
    {
        $shipping = ShippingMethod::create(['name' => ['en' => 'Courier', 'ru' => 'Курьер', 'uk' => "Кур'єр"], 'position' => 0]);
        $payment = PaymentMethod::create(['name' => ['en' => 'Cash', 'ru' => 'Наличные', 'uk' => 'Готівка'], 'instructions' => ['uk' => 'Сплатіть курʼєру'], 'position' => 0, 'enabled' => true]);
        $status = OrderStatus::create(['code' => 'lang_test', 'name' => ['en' => 'Packed', 'uk' => 'Запаковано'], 'color' => '#123456', 'position' => 0, 'inventory_action' => 'none']);

        $this->withSession(['admin_content_locale' => 'ru']);

        $this->put("http://localhost/admin/shipping/methods/{$shipping->id}", ['name' => 'Курьером', 'position' => 0])->assertSessionHasNoErrors();
        $this->put("http://localhost/admin/payments/methods/{$payment->id}", ['name' => 'Наличными', 'instructions' => 'Отдайте курьеру', 'position' => 0])->assertSessionHasNoErrors();
        $this->put("http://localhost/admin/orders/statuses/{$status->code}", ['name' => 'Упакован', 'color' => '#123456', 'inventory_action' => 'none'])->assertSessionHasNoErrors();

        $this->assertEquals(['en' => 'Courier', 'ru' => 'Курьером', 'uk' => "Кур'єр"], $shipping->fresh()->name);
        $this->assertEquals(['en' => 'Cash', 'ru' => 'Наличными', 'uk' => 'Готівка'], $payment->fresh()->name);
        $this->assertEquals(['uk' => 'Сплатіть курʼєру', 'ru' => 'Отдайте курьеру'], $payment->fresh()->instructions);
        $this->assertEquals(['en' => 'Packed', 'uk' => 'Запаковано', 'ru' => 'Упакован'], $status->fresh()->name);

        // Not the base language, so leaving it empty is "not translated yet".
        $this->put("http://localhost/admin/shipping/methods/{$shipping->id}", ['name' => '', 'position' => 0])->assertSessionHasNoErrors();
        $this->assertEquals(['en' => 'Courier', 'uk' => "Кур'єр"], $shipping->fresh()->name);

        // The form draws one field, in the language being written.
        $this->get("http://localhost/admin/shipping/methods/{$shipping->id}/edit")->assertOk()
            ->assertSee('name="name"', false)->assertDontSee('name="name_en"', false);
    }

    public function test_a_characteristic_value_keeps_a_language_the_screen_is_not_editing(): void
    {
        $feature = Feature::create(['name' => ['en' => 'Colour'], 'purpose' => 'filter', 'input_type' => 'select']);
        $value = $feature->values()->create(['value' => ['en' => 'Red', 'uk' => 'Червоний']]);

        $this->get("http://localhost/admin/catalog/features/{$feature->id}/edit")->assertOk()->assertSee('Червоний');

        $this->put("http://localhost/admin/catalog/features/{$feature->id}", [
            'name' => 'Colour', 'purpose' => 'filter', 'input_type' => 'select', 'shown_in_set' => '1', 'shown_in' => ['filters'],
            'values_json' => json_encode([['id' => $value->id, 'value' => ['en' => 'Scarlet', 'uk' => 'Червоний'], 'code' => '', 'position' => 0]]),
        ])->assertSessionHasNoErrors();

        $this->assertEquals(['en' => 'Scarlet', 'uk' => 'Червоний'], $value->fresh()->value);
    }
}

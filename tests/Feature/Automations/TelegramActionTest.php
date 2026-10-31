<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\RuleTemplate;
use Torgash\Checkout\Models\Order;

/**
 * "A new order" on the manager's phone. Pinned down: the text is rendered with
 * the order's variables and HTML-escaped values, the token never reaches the
 * run log, a blank token box keeps the stored one, and an exported template
 * carries neither the token nor the chat.
 */
class TelegramActionTest extends TestCase
{
    protected function order(): Order
    {
        return Order::create([
            'order_number' => 'TG-TLG'.random_int(1000, 9999),
            'customer_name' => 'O<b>Brien',
            'email' => 'buyer@example.test',
            'phone' => '+380501112233',
            'city' => 'Kyiv',
            'country' => 'UA',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'stock_deducted' => false,
            'items' => [['sku' => 'W-1', 'name' => 'Widget', 'qty' => 2, 'unit_price' => 50_00, 'line_total' => 100_00]],
        ])->fresh();
    }

    /** A message links its lines: a product's address and its card in the admin, '' once the product is gone. */
    public function test_a_line_carries_the_products_addresses(): void
    {
        $product = \Torgash\Catalog\Models\Product::create(['sku' => 'TLG-P', 'name' => ['en' => 'Widget'], 'slug' => 'tlg-widget', 'price' => 50_00, 'stock' => 5, 'status' => 'active']);
        $order = $this->order();
        $order->update(['items' => [
            ['product_id' => $product->id, 'sku' => 'TLG-P', 'name' => 'Widget', 'qty' => 1, 'unit_price' => 50_00, 'line_total' => 50_00],
            ['product_id' => $product->id + 100000, 'sku' => 'GONE', 'name' => 'Gone', 'qty' => 1, 'unit_price' => 50_00, 'line_total' => 50_00],
        ]]);

        $items = \Torgash\Automations\Context::forOrder($order->fresh(), 'checkout.order_placed')['order']['items'];

        $this->assertSame(route('catalog.product', 'tlg-widget'), $items[0]['url']);
        $this->assertSame(route('admin.catalog.products.edit', $product->id), $items[0]['admin_url']);
        $this->assertSame(['', ''], [$items[1]['url'], $items[1]['admin_url']]);
        $this->assertSame(['', [], ''], [$items[1]['variation'], $items[1]['features'], $items[1]['image_url']]);
    }

    /**
     * A line says what the product IS: its variation in one line, any
     * characteristic by its code, all of them in one line, the options
     * chosen with it — and, in the text, through the template's own paths.
     */
    public function test_a_line_says_its_variation_characteristics_and_options(): void
    {
        $colour = \Torgash\Catalog\Models\Feature::create(['name' => ['en' => 'Colour'], 'code' => 'TLG_COLOR', 'input_type' => 'select', 'purpose' => 'variation']);
        $black = \Torgash\Catalog\Models\FeatureValue::create(['feature_id' => $colour->id, 'value' => ['en' => 'Black'], 'slug' => 'tlg-black', 'sort' => 1]);
        \Torgash\Catalog\Models\FeatureValue::create(['feature_id' => $colour->id, 'value' => ['en' => 'White'], 'slug' => 'tlg-white', 'sort' => 2]);
        $memory = \Torgash\Catalog\Models\Feature::create(['name' => ['en' => 'Memory'], 'code' => 'TLG_MEMORY', 'input_type' => 'text']);

        $product = \Torgash\Catalog\Models\Product::create(['sku' => 'TLG-V', 'name' => ['en' => 'Phone'], 'slug' => 'tlg-phone', 'price' => 500_00, 'stock' => 5, 'status' => 'active']);
        $product->featureAssignments()->create(['feature_id' => $colour->id, 'feature_value_id' => $black->id]);
        $product->featureAssignments()->create(['feature_id' => $memory->id, 'value_text' => '256 GB']);

        $order = $this->order();
        $order->update(['items' => [[
            'product_id' => $product->id, 'sku' => 'TLG-V', 'name' => 'Phone', 'qty' => 1, 'unit_price' => 500_00, 'line_total' => 500_00,
            'options' => [['label' => 'Case: leather', 'qty' => 2, 'amount' => 20_00]],
        ]]]);

        $line = \Torgash\Automations\Context::forOrder($order->fresh(), 'checkout.order_placed')['order']['items'][0];

        $this->assertSame(['TLG_COLOR' => 'Black', 'TLG_MEMORY' => '256 GB'], $line['features']);
        $this->assertSame('Colour: Black; Memory: 256 GB', $line['features_text']);
        $this->assertSame('Case: leather ×2', $line['options_text']);
        $this->assertFalse($line['is_part']);

        $text = \Torgash\Automations\Template::render(
            "{{#order.items}}{{ item.name }} [{{ item.features.TLG_COLOR }}] {{ item.features_text }}{{/order.items}}",
            \Torgash\Automations\Context::forOrder($order->fresh(), 'checkout.order_placed'),
            \Torgash\Automations\Template::HTML,
        );
        $this->assertSame('Phone [Black] Colour: Black; Memory: 256 GB', $text);
        $this->assertArrayHasKey('item.url', \Torgash\Automations\Context::catalogue()['Order lines'] ?? array_merge(...array_values(\Torgash\Automations\Context::catalogue())));
    }

    protected function rule(array $config): Rule
    {
        $rule = Rule::create(['name' => 'Telegram', 'enabled' => true, 'event' => 'checkout.order_placed', 'delay_minutes' => 0]);
        $rule->actions()->create(['type' => 'telegram', 'config' => $config, 'position' => 0]);

        return $rule;
    }

    public function test_the_message_is_sent_with_the_orders_values_escaped(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $order = $this->order();
        $rule = $this->rule([
            'token' => '123:SECRET',
            'chat_id' => '-100500',
            'text' => "<b>Order {{ order.number }}</b>\n{{ order.customer_name }}, {{ order.total_formatted }}\n{{#order.items}}{{ item.name }} × {{ item.qty }}\n{{/order.items}}",
        ]);

        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-1'))->handle();

        Http::assertSent(function (SentRequest $request) use ($order) {
            return $request->url() === 'https://api.telegram.org/bot123:SECRET/sendMessage'
                && $request['chat_id'] === '-100500'
                && $request['parse_mode'] === 'HTML'
                && str_contains($request['text'], '<b>Order '.$order->order_number.'</b>')
                && str_contains($request['text'], 'O&lt;b&gt;Brien')
                && str_contains($request['text'], 'Widget × 2');
        });

        $run = RuleRun::where('rule_id', $rule->id)->latest('id')->firstOrFail();
        $this->assertSame(RuleRun::SENT, $run->status);
        $this->assertStringNotContainsString('SECRET', json_encode($run->detail));
    }

    public function test_telegrams_own_refusal_is_what_the_log_says(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

        $order = $this->order();
        $rule = $this->rule(['token' => '123:SECRET', 'chat_id' => 'nope', 'text' => 'Hi']);

        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-2'))->handle();

        $run = RuleRun::where('rule_id', $rule->id)->latest('id')->firstOrFail();
        $this->assertSame(RuleRun::FAILED, $run->status);
        $this->assertStringContainsString('chat not found', $run->summary);
    }

    public function test_a_blank_token_box_keeps_the_stored_token(): void
    {
        $rule = $this->rule(['token' => '123:SECRET', 'chat_id' => '-1', 'text' => 'Hi']);
        $actionId = $rule->actions()->value('id');

        $this->actingAs(User::role('admin')->firstOrFail())
            ->put("http://localhost/admin/automations/{$rule->id}", [
                'name' => 'Telegram',
                'event' => 'checkout.order_placed',
                'enabled' => 1,
                'actions' => [['id' => $actionId, 'type' => 'telegram', 'token' => '', 'chat_id' => '-2', 'text' => 'Hello']],
            ])
            ->assertRedirect();

        $action = $rule->actions()->firstOrFail();
        $this->assertSame('123:SECRET', $action->config('token'));
        $this->assertSame('-2', $action->config('chat_id'));
    }

    public function test_an_exported_template_asks_for_the_bot_and_the_chat(): void
    {
        $rule = $this->rule(['token' => '123:SECRET', 'chat_id' => '-100500', 'text' => 'New order {{ order.number }}']);

        $payload = RuleTemplate::export($rule);

        $this->assertStringNotContainsString('SECRET', json_encode($payload));
        $this->assertStringNotContainsString('-100500', json_encode($payload));
        $this->assertSame(['actions.0.token', 'actions.0.chat_id'], array_column(RuleTemplate::needs($payload), 'path'));

        $imported = RuleTemplate::import($payload, ['actions.0.token' => '9:NEW', 'actions.0.chat_id' => '-7']);
        $this->assertSame('9:NEW', $imported->actions->first()->config('token'));
        $this->assertSame('New order {{ order.number }}', $imported->actions->first()->config('text'));
    }
}

<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Telegram\Bot;
use Torgash\Automations\Telegram\Listening;
use Torgash\Automations\Telegram\Message;
use Torgash\Automations\Telegram\Updates;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

/**
 * Buttons under a Telegram message (PLAN §64.1): a press is a staff member's
 * own act — found by their linked Telegram account, checked against their
 * rights every time, done through the action a rule would run, written into
 * the history under their name, and done once.
 */
class TelegramButtonsTest extends TestCase
{
    protected const TOKEN = '123:SECRET';

    /** @var list<array{method: string, data: array<string, mixed>}> every Bot API call, in order */
    protected array $calls = [];

    /** @var list<array<string, mixed>> what the next getUpdates answers with */
    protected array $updates = [];

    protected Product $product;

    protected User $ivan;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(function (HttpRequest $request) {
            $method = basename(parse_url($request->url(), PHP_URL_PATH));
            $this->calls[] = ['method' => $method, 'data' => $request->data()];

            return Http::response(['ok' => true, 'result' => match ($method) {
                'sendMessage' => ['message_id' => 9001],
                'getUpdates' => array_splice($this->updates, 0),
                'getMe' => ['username' => 'shop_bot'],
                default => true,
            }]);
        });

        $this->product = Product::create(['sku' => 'TB-1', 'name' => ['en' => 'Lamp'], 'slug' => 'tb-lamp', 'price' => 50_00, 'stock' => 10, 'status' => 'active']);
        $this->ivan = User::factory()->create(['name' => 'Ivan']);
        $this->ivan->assignRole('admin');
    }

    public function test_a_message_goes_out_with_its_buttons_and_is_remembered(): void
    {
        [$order, $message] = $this->sent();

        $send = $this->last('sendMessage');
        $this->assertSame([[
            ['text' => 'Shipped', 'callback_data' => 'ta:'.$message->id.':0'],
            ['text' => 'Take it', 'callback_data' => 'ta:'.$message->id.':1'],
        ], [
            ['text' => 'Called', 'callback_data' => 'ta:'.$message->id.':2'],
        ]], $send['reply_markup']['inline_keyboard']);

        $this->assertSame(9001, $message->message_id);
        $this->assertSame(['order', $order->id], [$message->subject, $message->subject_id]);
        $this->assertStringNotContainsString(self::TOKEN, json_encode(Bot::first()->toArray()), 'the token is nowhere a row is printed');
    }

    public function test_somebody_telegram_does_not_know_is_told_so_and_nothing_happens(): void
    {
        [$order, $message] = $this->sent();

        $this->press($message, 0, from: 555);

        $this->assertSame('new', $order->fresh()->status);
        $this->assertTrue($this->last('answerCallbackQuery')['show_alert']);
        $this->assertNull($message->fresh()->pressed);
    }

    public function test_a_code_from_the_admin_links_a_telegram_account_to_its_staff_member(): void
    {
        $this->actingAs($this->ivan)->post('http://localhost/admin/automations/telegram/code')->assertSessionHas('telegram_code');
        $code = session('telegram_code');

        app(Updates::class)->handle($this->bot(), ['message' => ['text' => '/link '.$code, 'chat' => ['id' => -100500], 'from' => ['id' => 777, 'first_name' => 'Ваня']]]);

        $this->assertSame($this->ivan->id, (int) DB::table('m_automations_telegram_users')->where('telegram_id', 777)->value('user_id'));
        $this->assertStringContainsString('Ivan', $this->last('sendMessage')['text']);

        // The code is good once; a customer's account links nothing.
        app(Updates::class)->handle($this->bot(), ['message' => ['text' => '/start '.$code, 'chat' => ['id' => 1], 'from' => ['id' => 888]]]);
        $this->assertSame(0, DB::table('m_automations_telegram_users')->where('telegram_id', 888)->count());

        $customer = User::factory()->create();
        app(Updates::class)->handle($this->bot(), ['message' => ['text' => '/link '.Updates::linkCode($customer), 'chat' => ['id' => 1], 'from' => ['id' => 999]]]);
        $this->assertSame(0, DB::table('m_automations_telegram_users')->where('telegram_id', 999)->count());

        // "/id" answers with the chat's id, for the rule form.
        app(Updates::class)->handle($this->bot(), ['message' => ['text' => '/id@shop_bot', 'chat' => ['id' => -100777], 'from' => ['id' => 1]]]);
        $this->assertStringContainsString('-100777', $this->last('sendMessage')['text']);
    }

    public function test_a_press_does_what_the_admin_would_as_the_person_who_pressed_once(): void
    {
        $this->link($this->ivan, 777);
        [$order, $message] = $this->sent();
        $shipped = OrderStatus::where('code', '!=', 'new')->orderBy('position')->firstOrFail();

        $this->press($message, 0, from: 777);

        $order->refresh();
        $this->assertSame($shipped->code, $order->status);
        $this->assertTrue($order->logs()->get()->contains(fn ($log) => str_contains($log->text(), 'Ivan') && str_contains($log->text(), 'Telegram')), 'the history says who, and from where');

        $edit = $this->last('editMessageText');
        $this->assertSame(9001, $edit['message_id']);
        $this->assertStringContainsString('✅ <b>Shipped</b> — Ivan', $edit['text']);
        $this->assertArrayNotHasKey('reply_markup', $edit, 'the buttons are gone');
        $this->assertFalse((bool) ($this->last('answerCallbackQuery')['show_alert'] ?? false));
        $this->assertSame(RuleRun::SENT, RuleRun::latest('id')->firstOrFail()->status);

        // Pressed again — by anybody: told who already did it, nothing moves.
        $petr = User::factory()->create(['name' => 'Petr']);
        $petr->assignRole('admin');
        $this->link($petr, 778);
        $before = count($this->calls);
        $this->press($message, 1, from: 778);

        $this->assertNull($order->fresh()->manager_id);
        $this->assertStringContainsString('Ivan', $this->last('answerCallbackQuery')['text']);
        $this->assertSame($before + 1, count($this->calls), 'one answer, no edit');
    }

    public function test_take_it_assigns_whoever_pressed_and_a_note_writes_the_buttons_name(): void
    {
        $this->link($this->ivan, 777);

        [$order, $message] = $this->sent();
        $this->press($message, 1, from: 777);
        $this->assertSame($this->ivan->id, $order->fresh()->manager_id);

        [$other, $second] = $this->sent();
        $this->press($second, 2, from: 777);
        $this->assertTrue($other->logs()->get()->contains(fn ($log) => str_contains($log->text(), 'Called') && str_contains($log->text(), 'Ivan')));
    }

    public function test_a_right_taken_away_in_the_admin_is_gone_here_too(): void
    {
        Role::findOrCreate('packer', 'web');
        $packer = User::factory()->create(['name' => 'Packer']);
        $packer->assignRole('packer');
        $this->link($packer, 780);
        [$order, $message] = $this->sent();

        $this->press($message, 0, from: 780);

        $this->assertSame('new', $order->fresh()->status);
        $this->assertTrue($this->last('answerCallbackQuery')['show_alert']);
        $this->assertNull($message->fresh()->pressed, 'somebody who may still can');
    }

    public function test_a_press_is_a_persons_act_and_the_rules_that_wait_for_it_fire(): void
    {
        $this->link($this->ivan, 777);
        $waiting = Rule::create(['name' => 'On status', 'event' => 'checkout.order_status_changed', 'enabled' => true, 'delay_minutes' => 0]);
        $waiting->actions()->create(['type' => 'telegram', 'config' => ['token' => self::TOKEN, 'chat_id' => '-1', 'text' => 'Status now {{ order.status }}, by {{ actor.name }}'], 'position' => 0]);
        [, $message] = $this->sent();

        $this->press($message, 0, from: 777);

        $told = collect($this->calls)->where('method', 'sendMessage')->pluck('data.text')->filter(fn ($text) => str_starts_with((string) $text, 'Status now'));
        $this->assertCount(1, $told);
        $this->assertStringContainsString('by Ivan', $told->first());
    }

    public function test_presses_are_heard_by_asking_and_by_being_called(): void
    {
        $this->link($this->ivan, 777);
        [$order, $message] = $this->sent();
        $bot = $this->bot();

        // Asking: one update, read past, acted on.
        $this->updates = [['update_id' => 41, 'callback_query' => ['id' => 'q1', 'data' => 'ta:'.$message->id.':1', 'from' => ['id' => 777]]]];
        $this->artisan('automations:telegram-poll', ['--once' => true])->assertSuccessful();

        $this->assertSame($this->ivan->id, $order->fresh()->manager_id);
        $this->assertSame(42, $bot->fresh()->update_offset);

        // Being called: only for a bot set to it, and only with Telegram's secret.
        [$second, $next] = $this->sent();
        $update = ['update_id' => 43, 'callback_query' => ['id' => 'q2', 'data' => 'ta:'.$next->id.':1', 'from' => ['id' => 777]]];
        $address = 'http://localhost/automations/telegram/'.$bot->token_hash;

        $this->postJson($address, $update, ['X-Telegram-Bot-Api-Secret-Token' => $bot->secret])->assertNotFound();

        config(['app.url' => 'https://shop.example']);
        $this->assertNull(Listening::useWebhook($bot));
        $this->assertSame($bot->secret, $this->last('setWebhook')['secret_token']);

        $this->postJson($address, $update, ['X-Telegram-Bot-Api-Secret-Token' => 'guessed'])->assertNotFound();
        $this->assertNull($second->fresh()->manager_id);

        $this->postJson($address, $update, ['X-Telegram-Bot-Api-Secret-Token' => $bot->secret])->assertOk();
        $this->assertSame($this->ivan->id, $second->fresh()->manager_id);

        // A bot Telegram calls is not asked.
        $this->assertCount(0, Listening::bots()->where('mode', Bot::POLL));
    }

    public function test_the_form_keeps_the_buttons_it_understands_and_the_screen_opens(): void
    {
        $shipped = OrderStatus::where('code', '!=', 'new')->orderBy('position')->firstOrFail();

        $this->actingAs($this->ivan)->post('http://localhost/admin/automations', [
            'name' => 'Buttons', 'event' => 'checkout.order_placed', 'enabled' => '1',
            'actions' => [['type' => 'telegram', 'token' => self::TOKEN, 'chat_id' => '-1', 'text' => 'x', 'buttons' => [
                ['label' => 'Shipped', 'do' => 'set_status|status|'.$shipped->code],
                ['label' => 'Mine', 'do' => 'set_manager|manager_id|@me'],
                ['label' => 'Owl', 'do' => 'set_status|status|shipped_by_owl'],
                ['label' => '', 'do' => 'note||'],
            ]]],
        ])->assertSessionHasNoErrors();

        $rule = Rule::where('name', 'Buttons')->firstOrFail();
        $this->assertSame(['Shipped', 'Mine'], array_column($rule->actions->first()->config('buttons'), 'label'));

        $this->actingAs($this->ivan)->get('http://localhost/admin/automations/'.$rule->id.'/edit')->assertOk()
            ->assertSee(__('Buttons under the message'));
        $this->actingAs($this->ivan)->get('http://localhost/admin/automations/telegram')->assertOk()
            ->assertSee('@shop_bot')->assertSee(__('Get a code'));

        // Not a screen for a customer.
        $this->actingAs(User::factory()->create())->get('http://localhost/admin/automations/telegram')->assertForbidden();
    }

    /** A rule's message with three buttons, sent about a new order. @return array{0: Order, 1: Message} */
    protected function sent(): array
    {
        $shipped = OrderStatus::where('code', '!=', 'new')->orderBy('position')->firstOrFail();
        $rule = Rule::firstOrCreate(['name' => 'New order'], ['event' => 'checkout.order_placed', 'enabled' => true, 'delay_minutes' => 0]);

        if ($rule->wasRecentlyCreated) {
            $rule->actions()->create(['type' => 'telegram', 'position' => 0, 'config' => [
                'token' => self::TOKEN, 'chat_id' => '-100500', 'text' => 'Order {{ order.number }}',
                'buttons' => [
                    ['label' => 'Shipped', 'do' => 'set_status|status|'.$shipped->code],
                    ['label' => 'Take it', 'do' => 'set_manager|manager_id|@me'],
                    ['label' => 'Called', 'do' => 'note||'],
                ],
            ]]);
        }

        $order = Order::create([
            'order_number' => 'TG-B'.random_int(10000, 99999), 'customer_name' => 'Buyer', 'email' => 'buyer@example.test',
            'address_line' => '1 Street', 'city' => 'Town', 'currency_code' => 'USD',
            'subtotal' => 50_00, 'shipping_cost' => 0, 'total' => 50_00, 'status' => 'new', 'stock_deducted' => false,
            'items' => [['product_id' => $this->product->id, 'sku' => 'TB-1', 'name' => 'Lamp', 'qty' => 1, 'unit_price' => 50_00, 'line_total' => 50_00]],
        ])->fresh();

        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'send-'.$order->id))->handle();

        return [$order, Message::latest('id')->firstOrFail()];
    }

    protected function bot(): Bot
    {
        return Bot::for(self::TOKEN);
    }

    protected function link(User $user, int $telegramId): void
    {
        DB::table('m_automations_telegram_users')->insert(['user_id' => $user->id, 'telegram_id' => $telegramId, 'telegram_name' => $user->name, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function press(Message $message, int $button, int $from): void
    {
        app(Updates::class)->handle($this->bot(), ['callback_query' => ['id' => 'q-'.uniqid(), 'data' => 'ta:'.$message->id.':'.$button, 'from' => ['id' => $from]]]);
    }

    /** @return array<string, mixed> the data of the latest call of a Bot API method */
    protected function last(string $method): array
    {
        return collect($this->calls)->where('method', $method)->last()['data'] ?? [];
    }
}

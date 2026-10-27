<?php

namespace Tests\Feature\Wishlist;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Wishlist\Jobs\CheckPriceDrops;
use App\Core\Mail\EventMail;
use Torgash\Wishlist\Mail\WishlistLetters;
use Torgash\Wishlist\Models\WishList;
use Torgash\Wishlist\Restocks;

/**
 * Favourites, step 2 (PLAN §35.3): the letters the customer switched on, the
 * link they share, and what the shop learns — products and numbers only.
 */
class WishlistLettersTest extends TestCase
{
    public function test_only_an_account_switches_the_letters_on_and_the_list_remembers_its_language(): void
    {
        $this->post(route('wishlist.notify'), ['notify_price' => 1])->assertForbidden();

        $user = User::factory()->create();
        $this->actingAs($user)->post(route('wishlist.notify'), ['notify_price' => '1'])->assertRedirect();

        $list = WishList::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue($list->notify_price);
        $this->assertFalse($list->notify_stock, 'the other switch is untouched');
        $this->assertSame('en', $list->locale, 'the language the letters will be written in');

        $this->actingAs($user)->post(route('wishlist.notify'), ['notify_stock' => '1']);
        $this->assertTrue($list->fresh()->notify_price, 'switching one does not switch the other off');
        $this->assertTrue($list->fresh()->notify_stock);
    }

    /** One letter per list, past the threshold, and one drop is told once. */
    public function test_a_price_drop_is_told_once_and_only_past_the_threshold(): void
    {
        Mail::fake();
        app(SettingsManager::class)->set('torgash.wishlist', 'price_drop_percent', 10);

        $user = User::factory()->create();
        $list = $this->listFor($user, ['notify_price' => true]);
        $cheaper = $this->product('WLL-1', 150_00);
        $barely = $this->product('WLL-2', 195_00);
        $this->save($list, $cheaper, 200_00);
        $this->save($list, $barely, 200_00);

        // Somebody who did not ask hears nothing.
        $quiet = $this->listFor(User::factory()->create(), ['notify_price' => false]);
        $this->save($quiet, $cheaper, 200_00);

        CheckPriceDrops::dispatchSync();

        Mail::assertQueued(EventMail::class, 1);
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === WishlistLetters::CHEAPER && $mail->hasTo($user->email)
            && array_column($mail->payload['drops'], 'product_id') === [$cheaper->id]
            && $mail->payload['drops'][0]['old'] === 200_00 && $mail->payload['drops'][0]['new'] === 150_00);

        $this->assertSame(150_00, (int) DB::table('m_wishlist_items')->where('list_id', $list->id)->where('product_id', $cheaper->id)->value('price_at_add'));
        $this->assertSame(200_00, (int) DB::table('m_wishlist_items')->where('list_id', $quiet->id)->value('price_at_add'), 'a list that did not ask keeps its price');

        CheckPriceDrops::dispatchSync();
        Mail::assertQueued(EventMail::class, 1);
    }

    public function test_a_cheaper_product_nobody_can_buy_waits(): void
    {
        Mail::fake();

        $list = $this->listFor(User::factory()->create(), ['notify_price' => true]);
        $gone = $this->product('WLL-3', 100_00, ['stock' => 0, 'availability_status' => 'out_of_stock']);
        $this->save($list, $gone, 200_00);

        CheckPriceDrops::dispatchSync();

        Mail::assertNothingQueued();
        $this->assertSame(200_00, (int) DB::table('m_wishlist_items')->where('list_id', $list->id)->value('price_at_add'));
    }

    /** Everything one action brought back is ONE letter per list. */
    public function test_products_back_in_stock_are_one_letter(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $list = $this->listFor($user, ['notify_stock' => true]);
        $a = $this->product('WLL-4', 100_00, ['stock' => 0, 'availability_status' => 'out_of_stock']);
        $b = $this->product('WLL-5', 100_00, ['stock' => 0, 'availability_status' => 'out_of_stock']);
        $this->save($list, $a, 100_00);
        $this->save($list, $b, 100_00);

        $a->update(['stock' => 3, 'availability_status' => 'in_stock']);
        $b->update(['stock' => 3, 'availability_status' => 'in_stock']);
        Mail::assertNothingQueued();

        app(Restocks::class)->flush();

        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === WishlistLetters::BACK && $mail->hasTo($user->email)
            && collect($mail->payload['product_ids'])->sort()->values()->all() === [$a->id, $b->id]);
        Mail::assertQueued(EventMail::class, 1);
    }

    public function test_the_letter_names_the_product_and_the_way_out_in_the_readers_language(): void
    {
        $list = $this->listFor(User::factory()->create(), ['notify_price' => true, 'locale' => 'ru']);
        $product = $this->product('WLL-6', 150_00);

        $html = (new EventMail(WishlistLetters::CHEAPER, ['list' => $list, 'drops' => [['product_id' => $product->id, 'old' => 200_00, 'new' => 150_00]]], 'ru'))->render();

        $this->assertStringContainsString('WLL-6 thing', $html);
        $this->assertStringContainsString('/product/wll-6', $html);
        $this->assertStringContainsString('/wishlist/unsubscribe/'.$list->id.'/price', $html);
        $this->assertStringContainsString('Отписаться', $html);
    }

    public function test_the_way_out_is_signed(): void
    {
        $list = $this->listFor(User::factory()->create(), ['notify_price' => true, 'notify_stock' => true]);

        $this->get('http://localhost/wishlist/unsubscribe/'.$list->id.'/price')->assertForbidden();

        $this->get(URL::signedRoute('wishlist.unsubscribe', ['list' => $list->id, 'what' => 'price']))->assertOk();
        $this->assertFalse($list->fresh()->notify_price);
        $this->assertTrue($list->fresh()->notify_stock);
    }

    /** A link shows the products and nothing about whose they are; a new link retires the old. */
    public function test_a_shared_list_is_read_only_and_can_be_taken_back(): void
    {
        $user = User::factory()->create(['name' => 'Very Private Person']);
        $product = $this->product('WLL-7', 100_00);
        $this->actingAs($user)->post(route('wishlist.toggle', 'wll-7'));
        $this->actingAs($user)->post(route('wishlist.share'))->assertRedirect();

        $token = WishList::where('user_id', $user->id)->value('share_token');
        $this->assertNotNull($token);

        auth()->logout();
        $page = $this->get(route('wishlist.shared', $token))->assertOk();
        $page->assertSee('WLL-7 thing')->assertSee(route('cart.add', 'wll-7'), false)
            ->assertDontSee('Very Private Person')->assertDontSee(route('wishlist.remove', 'wll-7'), false);

        $this->actingAs($user)->post(route('wishlist.share'), ['renew' => 1]);
        $this->get(route('wishlist.shared', $token))->assertNotFound();

        $this->actingAs($user)->post(route('wishlist.unshare'));
        $this->assertNull(WishList::where('user_id', $user->id)->value('share_token'));
    }

    public function test_the_shop_sees_what_is_wanted_and_cannot_be_bought(): void
    {
        $list = $this->listFor(User::factory()->create(['name' => 'Very Private Person']), ['notify_stock' => true]);
        $this->save($list, $this->product('WLL-8', 100_00, ['stock' => 0, 'availability_status' => 'out_of_stock']), 100_00);
        $this->save($list, $this->product('WLL-9', 100_00), 100_00);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $page = $this->actingAs($admin)->get(route('admin.wishlist.stats'))->assertOk();
        $page->assertSee('WLL-8 thing')->assertSee('WLL-9 thing')->assertDontSee('Very Private Person');

        $this->actingAs(User::factory()->create())->get(route('admin.wishlist.stats'))->assertForbidden();
    }

    protected function listFor(User $user, array $extra = []): WishList
    {
        return WishList::create($extra + ['token' => WishList::newToken(), 'user_id' => $user->id, 'last_active_at' => now()]);
    }

    protected function save(WishList $list, Product $product, int $priceAtAdd): void
    {
        DB::table('m_wishlist_items')->insert(['list_id' => $list->id, 'product_id' => $product->id, 'price_at_add' => $priceAtAdd, 'created_at' => now()]);
    }

    protected function product(string $sku, int $price, array $extra = []): Product
    {
        return Product::create($extra + [
            'sku' => $sku, 'name' => ['en' => $sku.' thing'], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 10, 'status' => 'active',
        ]);
    }
}

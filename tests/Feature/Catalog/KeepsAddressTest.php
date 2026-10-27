<?php

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Slugs;

/**
 * Addresses follow the model (Support\KeepsAddress): the first live shop's
 * report (2026-10-27) — a rename from a script left no redirect, a deleted
 * category kept its address, and a "+" fell out of every address.
 */
class KeepsAddressTest extends TestCase
{
    protected function product(string $slug): Product
    {
        return Product::create(['sku' => 'KA-'.$slug.'-'.uniqid(), 'name' => ['en' => $slug], 'slug' => $slug, 'price' => 100_00, 'stock' => 1, 'status' => 'active']);
    }

    public function test_a_rename_from_anywhere_leaves_a_redirect(): void
    {
        $product = $this->product('ka-old');
        $product->update(['slug' => 'ka-new']);

        $this->assertSame('/product/ka-new', DB::table('redirects')->where('from_path', '/product/ka-old')->value('to_path'));

        $category = Category::create(['name' => ['en' => 'KA'], 'slug' => 'ka-phones']);
        $category->update(['slug' => 'ka-smartphones']);

        $this->assertSame('/catalog/ka-smartphones', DB::table('redirects')->where('from_path', '/catalog/ka-phones')->value('to_path'));
    }

    public function test_the_bin_gives_the_address_up_and_a_restore_takes_it_back(): void
    {
        $category = Category::create(['name' => ['en' => 'KA'], 'slug' => 'ka-bin']);
        $category->delete();

        $this->assertSame('ka-bin-deleted-'.$category->id, Category::withTrashed()->find($category->id)->slug);
        $this->assertFalse(DB::table('redirects')->where('from_path', '/catalog/ka-bin')->exists(), 'no redirect to a deleted address');

        // Free again: restored, it is its own address once more.
        Category::withTrashed()->find($category->id)->restore();
        $this->assertSame('ka-bin', $category->fresh()->slug);
        $this->assertFalse(DB::table('redirects')->where('from_path', '/catalog/ka-bin-deleted-'.$category->id)->exists());

        // Taken meanwhile: the new one keeps it, the restored one stays marked.
        $old = $this->product('ka-taken');
        $old->delete();
        $new = $this->product('ka-taken');
        Product::withTrashed()->find($old->id)->restore();

        $this->assertSame('ka-taken', $new->fresh()->slug);
        $this->assertSame('ka-taken-deleted-'.$old->id, $old->fresh()->slug);
    }

    public function test_redirects_on_rename_can_be_paused(): void
    {
        $product = $this->product('ka-pause');
        app(\App\Core\Seo\RedirectManager::class)->withoutRecording(fn () => $product->update(['slug' => 'ka-pause-tmp']));
        $this->assertFalse(DB::table('redirects')->where('from_path', '/product/ka-pause')->exists());

        app(\App\Core\Settings\SettingsManager::class)->set('core', 'redirect_on_rename', false);
        $product->update(['slug' => 'ka-pause-final']);
        $this->assertFalse(DB::table('redirects')->where('from_path', '/product/ka-pause-tmp')->exists());

        app(\App\Core\Settings\SettingsManager::class)->set('core', 'redirect_on_rename', true);
        $product->update(['slug' => 'ka-pause-back']);
        $this->assertTrue(DB::table('redirects')->where('from_path', '/product/ka-pause-final')->exists());
    }

    public function test_a_plus_is_a_word_in_an_address(): void
    {
        $this->assertSame('iphone-16-plus', Slugs::make('iPhone 16+'));
        $this->assertSame('wi-fi-6-plus-router', Slugs::make('Wi-Fi 6+ router'));
        $this->assertSame('iphone-16', Slugs::make('iPhone 16'));
    }
}

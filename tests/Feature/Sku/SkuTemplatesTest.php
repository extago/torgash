<?php

namespace Tests\Feature\Sku;

use App\Core\Settings\SettingsManager;
use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Sku\Generator;
use Torgash\Sku\Renumber;
use Torgash\Sku\Templates;

/**
 * A product's SKU by a template (torgash.sku): the catalogue lets a new
 * product be created blank and asks `catalog.product.sku` once its id is
 * known; the module answers by the template that applies — a category's own,
 * once the admin form has attached the categories — and renumbers what is
 * already there in the background.
 */
class SkuTemplatesTest extends TestCase
{
    public function test_without_a_template_a_blank_sku_becomes_p_and_the_id(): void
    {
        $this->set('on_create', 'off');

        $product = $this->product('');
        $this->assertSame('P-'.$product->id, $product->sku);
        $this->assertSame('P-'.$product->id, $product->fresh()->sku, 'written, not only in memory');
        $this->assertTrue($product->hasFallbackSku());

        $this->assertSame('TYPED-1', $this->product('TYPED-1')->sku, 'a typed one is kept');

        // Somebody already holds the next fallback: the model steps aside.
        $next = (int) Product::withTrashed()->max('id') + 1;
        $this->product('P-'.$next);
        $this->assertSame('P-'.($next + 1), $this->product('')->sku);
    }

    public function test_a_blank_sku_is_made_by_the_template_and_a_typed_one_kept(): void
    {
        $this->set('template', 'ART-{n:5}');

        $first = $this->product('');
        $second = $this->product('');

        $this->assertMatchesRegularExpression('/^ART-\d{5}$/', $first->sku);
        $this->assertSame((int) substr($first->sku, 4) + 1, (int) substr($second->sku, 4), 'one running number');
        $this->assertSame('MINE-7', $this->product('MINE-7')->sku);
    }

    public function test_always_replaces_even_a_typed_sku(): void
    {
        $this->set('template', 'ALW-{n:3}');
        $this->set('on_create', 'always');

        $this->assertMatchesRegularExpression('/^ALW-\d{3}$/', $this->product('TYPED-2')->sku);
    }

    public function test_the_admin_form_takes_the_category_template_once_the_categories_are_in(): void
    {
        $this->set('template', 'GEN-{n:4}');
        $parent = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'laptops', 'enabled' => true]);
        $child = Category::create(['name' => ['en' => 'Gaming'], 'slug' => 'gaming-laptops', 'parent_id' => $parent->id, 'enabled' => true]);
        app(CategoryTree::class)->flush();
        app(Templates::class)->put($parent->id, '{category:3}-{n:3}');

        $this->actingAs($this->admin())->post('http://localhost/admin/catalog/products', [
            'sku' => '', 'name' => 'A laptop', 'price' => '10', 'stock' => '1', 'status' => 'active',
            'categories' => [$child->id],
        ])->assertRedirect();

        // The child has no template of its own: the parent's, with the child's address.
        $this->assertMatchesRegularExpression('/^GAM-\d{3}$/', Product::where('name->en', 'A laptop')->firstOrFail()->sku);
    }

    public function test_the_generator(): void
    {
        $this->assertSame('4006381333931', Generator::ean13('400638133393'), 'the standard\'s own example');

        $product = $this->product('KEEP-1');
        $generator = app(Generator::class);

        // A token with nothing to say leaves no doubled or hanging dash.
        $this->assertSame('X-'.$product->id, $generator->render('X-{brand}-{id}', $product));
        $this->assertSame('', $generator->render('{brand}', $product));

        $ean = $generator->render('{ean13}', $product);
        $this->assertMatchesRegularExpression('/^2\d{12}$/', $ean);
        $this->assertSame($ean, Generator::ean13(substr($ean, 0, 12)), 'a valid check digit');

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $generator->render('{rand:6}', $product));

        // A template that cannot vary makes its SKU once, then says it cannot.
        $this->assertNull($generator->make('KEEP-1', $this->product('')), 'taken, and nothing to change');
        $this->assertSame(['{nope}'], Generator::unknownTokens('A-{n:3}-{nope}'));
    }

    public function test_renumbering_in_the_background(): void
    {
        $this->set('on_create', 'off');
        $this->set('template', 'REN-{n:4}');
        $category = Category::create(['name' => ['en' => 'Renumbered'], 'slug' => 'renumbered', 'enabled' => true]);
        app(CategoryTree::class)->flush();

        $blank = $this->product('');
        $typed = $this->product('HAND-1');
        $elsewhere = $this->product('');
        $blank->categories()->attach($category->id);
        $typed->categories()->attach($category->id);

        $this->runJob(new Renumber('fallback', $category->id));
        $this->assertMatchesRegularExpression('/^REN-\d{4}$/', $blank->fresh()->sku, 'nobody chose it');
        $this->assertSame('HAND-1', $typed->fresh()->sku, 'typed: kept');
        $this->assertSame('P-'.$elsewhere->id, $elsewhere->fresh()->sku, 'outside the category');

        $this->runJob(new Renumber('all', $category->id));
        $this->assertMatchesRegularExpression('/^REN-\d{4}$/', $typed->fresh()->sku, '"every product" means the typed ones too');
    }

    /**
     * Starting over: numbers from 1 in the order the products were added,
     * even where the SKU a product should get is held by another one.
     */
    public function test_clearing_every_sku_numbers_the_shop_again_from_one(): void
    {
        $this->set('on_create', 'off');
        $this->set('template', 'NEW-{n:3}');

        $first = $this->product('NEW-002');
        $second = $this->product('NEW-001');
        $third = $this->product('HAND-9');
        $before = Product::query()->where('id', '<', $first->id)->count();
        app(Generator::class)->render('NEW-{n:3}', $first); // the counter moves on: a restart resets it

        $this->runJob(new Renumber('restart'));

        $this->assertSame(sprintf('NEW-%03d', $before + 1), $first->fresh()->sku);
        $this->assertSame(sprintf('NEW-%03d', $before + 2), $second->fresh()->sku, 'NEW-001 was held by another product: freed first');
        $this->assertSame(sprintf('NEW-%03d', $before + 3), $third->fresh()->sku);
        $this->assertSame(0, Renumber::parked(Product::query())->count());

    }

    public function test_a_stopped_restart_puts_back_every_old_sku_nobody_took(): void
    {
        $kept = $this->product('OLD-1');
        $lost = $this->product('OLD-2');
        Product::query()->whereKey([$kept->id, $lost->id])->update(['sku' => \Illuminate\Support\Facades\DB::raw("'~~' || sku")]);
        $this->product('OLD-2');

        Renumber::unpark();

        $this->assertSame('OLD-1', $kept->fresh()->sku);
        $this->assertSame('~~OLD-2', $lost->fresh()->sku, 'taken since: left parked, and the screen counts it');
        $this->assertSame(1, Renumber::parked(Product::query())->count());
    }

    public function test_the_screen(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->get('http://localhost/admin/sku')->assertOk()->assertSee('SKU-{n:6}');

        $this->actingAs($admin)->post('http://localhost/admin/sku', ['template' => 'A-{nope}', 'on_create' => 'blank'])
            ->assertSessionHasErrors('template');

        $this->actingAs($admin)->post('http://localhost/admin/sku', ['template' => 'B-{n:3}', 'on_create' => 'always'])
            ->assertRedirect('http://localhost/admin/sku');
        $this->assertSame('B-{n:3}', setting('torgash.sku', 'template'));
        $this->assertSame('always', setting('torgash.sku', 'on_create'));

        $this->actingAs($admin)->post('http://localhost/admin/sku/renumber', ['scope' => 'fallback'])->assertRedirect();
        $this->assertSame(1, BackgroundTask::where('name', 'Give products without a chosen SKU one by the template')->count());
    }

    protected function runJob(Renumber $job): void
    {
        $job->taskId = BackgroundTask::create(['name' => $job->label(), 'module' => 'torgash.sku', 'status' => BackgroundTask::QUEUED])->id;
        $job->handle();
    }

    protected function product(string $sku): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'sku' => $sku, 'name' => ['en' => 'SKU test '.$n], 'slug' => 'sku-test-'.$n.'-'.uniqid(),
            'price' => 100_00, 'stock' => 1, 'status' => 'active',
        ]);
    }

    protected function set(string $key, string $value): void
    {
        app(SettingsManager::class)->set('torgash.sku', $key, $value);
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }
}

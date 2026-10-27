<?php

namespace Tests\Feature\Landings;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Landings\Landings;
use Torgash\Landings\Models\Landing;
use Torgash\Landings\Zip;

/**
 * Product landings (torgash.landings): HTML + CSS + files on a product's page
 * above, below or INSTEAD of its description, or as a tab; for whom by core's
 * display rules and products picked or left out; the description itself
 * untouched for feeds.
 */
class LandingsTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->product = $this->product('LND-1', '<p>The product\'s own words.</p>');
    }

    public function test_the_four_places(): void
    {
        $this->landing(['placement' => 'before', 'html' => ['en' => '<p>ABOVE-IT</p>']]);
        $this->landing(['placement' => 'after', 'html' => ['en' => '<p>BELOW-IT</p>']]);
        $this->landing(['placement' => 'tab', 'tab_title' => ['en' => 'Why us'], 'html' => ['en' => '<p>IN-A-TAB</p>']]);

        // The page's body: the head's meta description says the words too.
        $page = substr($this->page($this->product), strpos($this->page($this->product), '<main'));
        $this->assertLessThan(strpos($page, 'own words'), strpos($page, 'ABOVE-IT'));
        $this->assertGreaterThan(strpos($page, 'own words'), strpos($page, 'BELOW-IT'));
        $this->assertStringContainsString('Why us', $page);
        $this->assertStringContainsString('IN-A-TAB', $page);

        $this->landing(['placement' => 'instead', 'html' => ['en' => '<p>THE-LANDING</p>']]);
        $page = $this->page($this->product);
        $this->assertStringContainsString('own words', substr($page, 0, strpos($page, '<main')), 'the meta description is still the text');
        $page = substr($page, strpos($page, '<main'));
        $this->assertStringContainsString('THE-LANDING', $page);
        $this->assertStringNotContainsString('own words', $page, 'the text is not printed…');
        $this->assertStringContainsString('ABOVE-IT', $page, '…what stands around it still is');
        $this->assertStringContainsString('own words', $this->product->fresh()->description['en'], 'and the description itself is untouched');
    }

    public function test_a_landing_instead_gives_a_product_without_text_its_description_section(): void
    {
        $bare = $this->product('LND-2', null);
        $this->assertStringNotContainsString('LANDING-ONLY', $this->page($bare));

        $this->landing(['placement' => 'instead', 'html' => ['en' => '<p>LANDING-ONLY</p>']]);
        $this->assertStringContainsString('LANDING-ONLY', $this->page($bare));
    }

    public function test_for_whom(): void
    {
        $laptops = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'lnd-laptops', 'enabled' => true]);
        $gaming = Category::create(['name' => ['en' => 'Gaming'], 'slug' => 'lnd-gaming', 'parent_id' => $laptops->id, 'enabled' => true]);
        app(CategoryTree::class)->flush();
        $inGaming = $this->product('LND-3', '<p>x</p>');
        $inGaming->categories()->attach($gaming->id);
        $other = $this->product('LND-4', '<p>x</p>');

        $byCategory = $this->landing(['html' => ['en' => '<p>BY-CATEGORY</p>'], 'rules' => [['kind' => 'product_category', 'operator' => 'in', 'value' => [$laptops->id]]]]);
        $this->assertStringContainsString('BY-CATEGORY', $this->page($inGaming), 'a category takes its subcategories');
        $this->assertStringNotContainsString('BY-CATEGORY', $this->page($other));

        // Picked by hand: shown although the rules say no; left out: hidden although they say yes.
        DB::table('m_landing_products')->insert([
            ['landing_id' => $byCategory->id, 'product_id' => $other->id, 'excluded' => false],
            ['landing_id' => $byCategory->id, 'product_id' => $inGaming->id, 'excluded' => true],
        ]);
        $this->assertStringContainsString('BY-CATEGORY', $this->page($other));
        $this->assertStringNotContainsString('BY-CATEGORY', $this->page($inGaming));

        // No rules and products picked: only those.
        $picked = $this->landing(['html' => ['en' => '<p>ONLY-PICKED</p>']]);
        DB::table('m_landing_products')->insert(['landing_id' => $picked->id, 'product_id' => $other->id, 'excluded' => false]);
        $this->assertStringContainsString('ONLY-PICKED', $this->page($other));
        $this->assertStringNotContainsString('ONLY-PICKED', $this->page($inGaming));
    }

    public function test_switched_off_and_dates_and_a_staff_preview(): void
    {
        $off = $this->landing(['enabled' => false, 'html' => ['en' => '<p>SWITCHED-OFF</p>']]);
        $this->landing(['starts_at' => now()->addDay(), 'html' => ['en' => '<p>NOT-YET</p>']]);
        $this->landing(['ends_at' => now()->subDay(), 'html' => ['en' => '<p>OVER</p>']]);

        $page = $this->page($this->product);
        foreach (['SWITCHED-OFF', 'NOT-YET', 'OVER'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $page);
        }

        $url = 'http://localhost/product/'.$this->product->slug.'?landing_preview='.$off->id;
        $this->assertStringNotContainsString('SWITCHED-OFF', $this->get($url)->getContent(), 'a visitor cannot preview');
        $this->assertStringContainsString('SWITCHED-OFF', $this->actingAs($this->admin())->get($url)->getContent());
    }

    public function test_what_is_printed(): void
    {
        $landing = $this->landing([
            'html' => ['en' => '<h2>{product_name}</h2><img src="assets/hero.webp" alt=""><script>alert(1)</script><a href="#" onclick="x()">go</a>', 'ru' => '<p>ПО-РУССКИ</p>'],
            'css' => "h2 { color: red }\n@keyframes spin { from { rotate: 0 } to { rotate: 1turn } }\n@import url(https://evil.test/x.css);\n.x { background: url(assets/bg.webp) } </style><script>",
        ]);

        $page = $this->page($this->product);
        $this->assertStringContainsString('<h2>LND-1 product</h2>', $page, 'placeholders, after cleaning');
        $this->assertStringContainsString('/landings/'.$landing->id.'/assets/hero.webp', $page);
        $this->assertStringNotContainsString('alert(1)', $page);
        $this->assertStringNotContainsString('onclick', $page);
        // Its own shadow root: the shop's CSS cannot reach in, its CSS cannot leak out.
        $this->assertStringContainsString('data-landing="'.$landing->id.'"><template shadowrootmode="open"><style>', $page);
        $this->assertStringContainsString('@keyframes spin', $page);
        // Made once a page (the description is printed by both layouts, so it rides along twice — harmless).
        $this->assertGreaterThanOrEqual(1, substr_count($page, 'htmx:afterSwap'), 'the attaching script');
        $this->assertStringNotContainsString('evil.test', $page);
        $this->assertStringNotContainsString('</style><script>', $page, 'the CSS cannot end its <style>');

        // Another language has its own text; a language without one falls back to the base.
        $renderer = app(\Torgash\Landings\Renderer::class);
        app()->setLocale('ru');
        $this->assertStringContainsString('ПО-РУССКИ', $renderer->render($landing, $this->product));
        app()->setLocale('uk');
        $this->assertStringContainsString('<h2>', $renderer->render($landing, $this->product));
        app()->setLocale('en');
    }

    public function test_a_zip_in_and_out(): void
    {
        $landing = $this->landing(['html' => ['en' => '<p>old</p>']]);
        Storage::disk('public')->put($landing->folder().'/assets/stale.webp', 'x');

        $zipPath = tempnam(sys_get_temp_dir(), 'z').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('design/index.html', '<!doctype html><html><head><title>T</title><style>.a{color:blue}</style><link rel="stylesheet" href="x.css"></head><body><div class="a">NEW-ONE</div><script>bad()</script></body></html>');
        $zip->addFromString('design/index.ru.html', '<div>НОВЫЙ</div>');
        $zip->addFromString('design/style.css', '.b{color:green}');
        $zip->addFromString('design/assets/pic.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $zip->addFromString('design/assets/evil.svg', '<svg onload="alert(1)"/>');
        $zip->addFromString('design/assets/../../escape.png', 'x');
        $zip->close();

        $report = app(Zip::class)->import($landing, $zipPath, 'en');
        $landing->refresh();

        $this->assertSame('<div class="a">NEW-ONE</div>', $landing->html['en']);
        $this->assertSame('<div>НОВЫЙ</div>', $landing->html['ru']);
        $this->assertStringContainsString('.a{color:blue}', $landing->css, '<style> moved to the CSS');
        $this->assertStringContainsString('.b{color:green}', $landing->css);
        $this->assertSame(1, $report['files']);
        $this->assertContains('assets/evil.svg', $report['skipped']);
        $this->assertSame(['script' => 1, 'link' => 1, 'title' => 1], $report['removed']);
        Storage::disk('public')->assertExists($landing->folder().'/assets/pic.png');
        Storage::disk('public')->assertMissing($landing->folder().'/assets/stale.webp');
        $this->assertSame([], Storage::disk('public')->files('escape.png'));

        $out = new \ZipArchive;
        $out->open(app(Zip::class)->export($landing, 'en'));
        $this->assertSame('<div class="a">NEW-ONE</div>', $out->getFromName('index.html'));
        $this->assertSame('<div>НОВЫЙ</div>', $out->getFromName('index.ru.html'));
        $this->assertNotFalse($out->getFromName('assets/pic.png'));
    }

    public function test_the_admin_screens_and_the_product_form_tab(): void
    {
        $admin = $this->admin();
        $other = $this->product('LND-5', '<p>x</p>');

        $this->actingAs($admin)->get('http://localhost/admin/landings')->assertOk();
        $this->actingAs($admin)->get('http://localhost/admin/landings/create')->assertOk();

        $this->actingAs($admin)->post('http://localhost/admin/landings', [
            'name' => 'Promo', 'enabled' => '1', 'placement' => 'after', 'sort' => '5',
            'space_top' => 'small', 'space_bottom' => 'none', 'html' => '<p>PROMO</p>', 'css' => '',
            'picked' => [$this->product->id], 'excluded' => [$other->id],
        ])->assertRedirect();

        $landing = Landing::where('name', 'Promo')->firstOrFail();
        $this->assertSame(['en' => '<p>PROMO</p>'], $landing->html);
        $this->actingAs($admin)->get('http://localhost/admin/landings/'.$landing->id.'/edit')->assertOk()->assertSee('Promo');
        $this->assertSame(2, DB::table('m_landing_products')->where('landing_id', $landing->id)->count());

        // The product form shows the tab, and its answer is saved with the product.
        $this->actingAs($admin)->get('http://localhost/admin/catalog/products/'.$other->id.'/edit')->assertOk()->assertSee('landings['.$landing->id.']', false);
        $this->actingAs($admin)->put('http://localhost/admin/catalog/products/'.$other->id, [
            'sku' => 'LND-5', 'name' => 'LND-5 product', 'price' => '10', 'stock' => '1', 'status' => 'active',
            'landings_form' => '1', 'landings' => [$landing->id => 'picked'],
        ])->assertRedirect();
        $this->assertFalse((bool) DB::table('m_landing_products')->where(['landing_id' => $landing->id, 'product_id' => $other->id])->value('excluded'));

        $this->actingAs($admin)->post('http://localhost/admin/landings/'.$landing->id.'/copy')->assertRedirect();
        $this->assertFalse(Landing::where('name', 'like', 'Promo (%')->firstOrFail()->enabled, 'a copy is switched off');

        $this->actingAs($admin)->delete('http://localhost/admin/landings/'.$landing->id)->assertRedirect();
        $this->assertNull(Landing::find($landing->id));
    }

    public function test_a_file_must_be_what_its_name_says(): void
    {
        $landing = $this->landing([]);

        $this->actingAs($this->admin())->post('http://localhost/admin/landings/'.$landing->id.'/files', [
            'files' => [
                UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')),
                UploadedFile::fake()->createWithContent('page.png', '<html><script>x</script></html>'),
                UploadedFile::fake()->createWithContent('logo.svg', '<svg/>'),
            ],
        ])->assertRedirect()->assertSessionHasErrors('files');

        Storage::disk('public')->assertExists($landing->folder().'/assets/photo.png');
        Storage::disk('public')->assertMissing($landing->folder().'/assets/page.png');
        Storage::disk('public')->assertMissing($landing->folder().'/assets/logo.svg');
    }

    /** Folded at the description's height, with the space around it kept outside the fold; a tab never folds. */
    public function test_a_landing_folds_like_the_description_when_asked(): void
    {
        $this->landing(['placement' => 'instead', 'fold' => true, 'space_bottom' => 'large', 'html' => ['en' => '<p>FOLDED</p>']]);

        $page = $this->page($this->product);
        $this->assertStringContainsString('FOLDED', $page);
        $this->assertStringContainsString("max-height: 420px", $page);
        $this->assertStringContainsString('Read in full', $page);
        $this->assertMatchesRegularExpression('~<div class="mb-10"><div x-data=~', $page);

        app(\App\Core\Settings\SettingsManager::class)->set('torgash.catalog', 'description_fold_height', 0);
        $this->assertStringNotContainsString('Read in full', $this->page($this->product), '0 there means nothing folds');
    }

    protected function page(Product $product): string
    {
        app(Landings::class)->flush();

        return $this->get('http://localhost/product/'.$product->slug)->assertOk()->getContent();
    }

    protected function landing(array $attributes): Landing
    {
        $landing = Landing::create($attributes + ['name' => 'L', 'placement' => 'after', 'html' => ['en' => '<p>L</p>']]);
        app(Landings::class)->flush();

        return $landing;
    }

    protected function product(string $sku, ?string $description): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $sku.' product'], 'slug' => strtolower($sku),
            'description' => $description === null ? null : ['en' => $description],
            'price' => 100_00, 'stock' => 5, 'status' => 'active',
        ]);
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Core\Support\SafeSvg;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Labels;

/**
 * A label drawn as a TILE — a square with a picture (an upload, SVG included,
 * or a Lucide icon) and its words under it — and the SVG an admin uploads,
 * which is published only once it is nothing but a drawing.
 */
class LabelTileTest extends TestCase
{
    public function test_an_svg_keeps_its_drawing_and_loses_everything_that_could_run(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" onload="alert(1)">'
            .'<script>alert(2)</script><foreignObject><iframe src="https://evil.test"/></foreignObject>'
            .'<a href="javascript:alert(3)"><path d="M0 0h24v24H0z" fill="url(https://evil.test/x)" onclick="x()"/></a>'
            .'<circle cx="12" cy="12" r="5" fill="url(#g)"/><use href="https://evil.test/s.svg#a"/></svg>';

        $clean = SafeSvg::clean($dirty);

        $this->assertNotNull($clean);
        foreach (['script', 'onload', 'onclick', 'foreignObject', 'iframe', 'javascript:', 'evil.test', '<a '] as $gone) {
            $this->assertStringNotContainsString($gone, $clean);
        }
        $this->assertStringContainsString('<circle', $clean);
        $this->assertStringContainsString('url(#g)', $clean, 'a reference inside the file stays');
        $this->assertStringContainsString('viewBox="0 0 24 24"', $clean);

        $this->assertNull(SafeSvg::clean('<!DOCTYPE svg [<!ENTITY x "y">]><svg/>'), 'no entities, no doctype');
        $this->assertNull(SafeSvg::clean('<html><body>not a drawing</body></html>'));
        $this->assertNull(SafeSvg::clean('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'blank once scrubbed');
    }

    /** Colours an editor wrote as CSS survive as attributes; the CSS itself does not. */
    public function test_an_svg_keeps_the_colours_its_editor_wrote_as_css(): void
    {
        $clean = (string) SafeSvg::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 10">'
            .'<defs><style>/* Illustrator */ .cls-1{fill:#e30613;} .cls-2, .cls-3{fill:#1d1d1b; stroke:url(https://evil.test/x)} @import url(https://evil.test/a.css);</style></defs>'
            .'<path class="cls-1" d="M0 0h10v10H0z"/><path class="cls-3" d="M10 0h10v10H10z"/>'
            .'<rect x="20" width="10" height="10" class="cls-1" style="fill:#00ff00;stroke-width:2;behavior:url(x.htc)"/></svg>');

        $this->assertStringContainsString('d="M0 0h10v10H0z" fill="#e30613"', $clean, 'a class rule');
        $this->assertStringContainsString('fill="#1d1d1b"', $clean, 'one of several selectors');
        $this->assertStringContainsString('fill="#00ff00" stroke-width="2"', $clean, 'its own style beats the class');
        foreach (['<style', 'style=', 'evil.test', 'behavior', '@import'] as $gone) {
            $this->assertStringNotContainsString($gone, $clean);
        }
    }

    public function test_the_form_takes_an_svg_for_a_tile_and_the_page_draws_it(): void
    {
        Storage::fake('public');
        $this->actingAs(User::role('admin')->firstOrFail());
        $label = Label::create(['name' => ['en' => 'LT'], 'name_for_admin' => 'LT tile', 'color' => 'primary', 'enabled' => true]);

        $svg = UploadedFile::fake()->createWithContent('ram.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>x</script><rect width="24" height="24"/></svg>');

        $this->put('http://localhost/admin/catalog/labels/'.$label->id, [
            'name' => '32 GB', 'name_for_admin' => 'LT tile', 'style' => 'tile', 'icon' => 'no-such-icon',
            'color' => 'info', 'size_detail' => 'md', 'image' => $svg,
            'position_list' => 'hidden', 'position_detail' => 'under-buy', 'enabled' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $label->refresh();
        $this->assertSame('tile', $label->style);
        $this->assertNull($label->icon, 'an icon the pack does not have is not kept');
        $stored = $label->getFirstMedia('image');
        $this->assertStringEndsWith('.svg', $stored->file_name);
        $this->assertStringNotContainsString('<script', file_get_contents($stored->getPath()));

        $product = Product::create(['sku' => 'LT-1', 'name' => ['en' => 'Gateway'], 'slug' => 'lt-gateway', 'price' => 100_00, 'stock' => 1, 'status' => 'active']);
        $product->labels()->sync([$label->id]);
        app(Labels::class)->flush();

        $html = $this->get('http://localhost/product/lt-gateway')->assertOk()->getContent();
        $this->assertStringContainsString('torgash-label-tile', $html);
        $this->assertStringContainsString('--tile-side: 60px', $html);
        $this->assertStringContainsString('>32 GB</span>', $html);
    }

    public function test_a_tile_without_a_picture_wears_its_icon(): void
    {
        $label = Label::create(['name' => ['en' => '24 cores'], 'name_for_admin' => 'LT cpu', 'style' => 'tile', 'icon' => 'cpu', 'color' => 'info', 'enabled' => true, 'position_detail' => 'under-buy']);
        $product = Product::create(['sku' => 'LT-2', 'name' => ['en' => 'Server'], 'slug' => 'lt-server', 'price' => 100_00, 'stock' => 1, 'status' => 'active']);
        $product->labels()->sync([$label->id]);
        app(Labels::class)->flush();

        $html = $this->get('http://localhost/product/lt-server')->assertOk()->getContent();
        $tile = substr($html, (int) strpos($html, 'torgash-label-tile'), 1500);
        $this->assertStringContainsString('<svg', $tile);
        $this->assertStringContainsString('24 cores', $tile);
    }
}

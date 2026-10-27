<?php

namespace Tests\Feature\Model3d;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Model3d\Models\Model3d;

/**
 * A product's 3D model: taken only when its bytes are binary glTF, drawn in
 * the gallery as a played item whose viewer is not on the page until the
 * slide is pressed, and gone with the product.
 */
class Model3dTest extends TestCase
{
    protected Product $product;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->product = Product::create([
            'sku' => 'M3D-1', 'name' => ['en' => 'Lamp'], 'slug' => 'm3d-lamp', 'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);
        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_only_binary_gltf_is_taken(): void
    {
        $base = 'http://localhost/admin/model3d/product/'.$this->product->id;

        $this->actingAs($this->admin)->post($base, ['model' => $this->file('lamp.glb', $this->glb())], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('items.0.name', 'lamp.glb');

        $model = Model3d::firstOrFail();
        $this->assertStringEndsWith('.glb', $model->path);
        $this->assertStringNotContainsString('lamp', $model->path, 'the stored name is ours');
        Storage::disk('public')->assertExists($model->path);

        // A renamed file of anything else, and a model cut short in transit.
        $this->actingAs($this->admin)->post($base, ['model' => $this->file('shell.glb', "<?php echo 'hi';")], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($this->admin)->post($base, ['model' => $this->file('cut.glb', substr($this->glb(), 0, 40))], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(1, Model3d::count());
    }

    public function test_the_gallery_draws_it_and_the_viewer_waits_for_a_press(): void
    {
        $model = Model3d::create([
            'owner_type' => 'product', 'owner_id' => $this->product->id, 'path' => 'model3d/product/'.$this->product->id.'/x.glb',
            'original_name' => 'lamp.glb', 'size' => 100, 'title' => ['en' => 'The lamp, turning'],
        ]);

        $page = $this->get('http://localhost/product/m3d-lamp')->assertOk()->getContent();

        $this->assertStringContainsString('3D view: The lamp, turning', $page);
        // The viewer is in the page only as an inert template, and its 1 MB
        // script only as an address to import when a slide is pressed.
        $this->assertMatchesRegularExpression('#<template data-video-player>\s*<div x-data="torgashModel3d[^>]*>\s*<model-viewer x-ref="viewer" src="[^"]*x\.glb"#', $page);
        // Its controls: the turning, the zoom, the first view, the whole screen.
        $this->assertStringContainsString('aria-label="Back to the first view"', $page);
        $this->assertStringContainsString('aria-label="Zoom in"', $page);
        $this->assertStringNotContainsString('<script type="module" src="'.url('/modules/torgash.model3d'), $page);
        $this->assertStringContainsString('torgash:gallery-play', $page);

        $this->product->forceDelete();
        $this->assertNull(Model3d::find($model->id));
    }

    /** The smallest valid binary glTF: header, a JSON chunk, nothing else. */
    protected function glb(): string
    {
        $json = str_pad('{"asset":{"version":"2.0"}}', 28, ' ');
        $body = pack('VV', strlen($json), 0x4E4F534A).$json;

        return 'glTF'.pack('VV', 2, 12 + strlen($body)).$body;
    }

    /** A real upload over real bytes — never UploadedFile::fake(), which answers from the name. */
    protected function file(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'm3d');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}

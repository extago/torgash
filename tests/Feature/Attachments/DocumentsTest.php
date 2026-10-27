<?php

namespace Tests\Feature\Attachments;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Attachments\Models\Attachment;
use Torgash\Catalog\Models\Product;

/**
 * A product's documentation: uploaded on the form (type read from the bytes,
 * programs refused), titled and ordered with the product's Save, listed in
 * the page's own section and handed over only through the shop.
 */
class DocumentsTest extends TestCase
{
    protected Product $product;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->product = Product::create([
            'sku' => 'DOC-1', 'name' => ['en' => 'Router'], 'slug' => 'doc-router', 'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);
        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_a_file_is_taken_by_what_it_is_and_a_program_is_refused(): void
    {
        $base = 'http://localhost/admin/attachments/product/'.$this->product->id;

        $this->actingAs($this->admin)->post($base.'/files', ['files' => [$this->pdf('Router manual.pdf')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('items.0.details', 'PDF · 1 KB');

        $stored = Attachment::firstOrFail();
        $this->assertSame('pdf', $stored->extension);
        $this->assertStringEndsWith('.pdf', $stored->path);
        $this->assertStringNotContainsString('manual', $stored->path, 'the stored name is ours');
        Storage::disk('private')->assertExists($stored->path);

        // A script under a document's name has no extension the shop takes.
        $this->actingAs($this->admin)->post($base.'/files', ['files' => [$this->file('spec.pdf', "<?php echo 'hi';")]], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->actingAs($this->admin)->post($base.'/files', ['files' => [$this->file('page.html', '<html><script>alert(1)</script></html>')]], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(1, Attachment::count());

        $this->actingAs($this->admin)->postJson($base.'/link', ['url' => 'javascript:alert(1)'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson($base.'/link', ['url' => 'https://example.com/manual.pdf'])->assertOk();
        $this->assertSame(2, Attachment::count());
    }

    public function test_the_page_lists_them_and_the_shop_hands_them_over(): void
    {
        $file = $this->upload('Datasheet.pdf');
        $link = Attachment::create(['owner_type' => 'product', 'owner_id' => $this->product->id, 'url' => 'https://maker.example/help', 'position' => 2]);

        // Titles (in the content language), who sees each and the order, with the product's Save.
        $this->actingAs($this->admin)->withSession(['admin_content_locale' => 'ru'])
            ->put('http://localhost/admin/catalog/products/'.$this->product->id, [
                'sku' => 'DOC-1', 'slug' => 'doc-router', 'name' => 'Роутер', 'price' => '10', 'stock' => '1', 'status' => 'active',
                'documents' => [
                    $file->id => ['title' => 'Спецификация', 'access' => 'account', 'position' => 2],
                    $link->id => ['title' => '', 'access' => 'all', 'position' => 1],
                ],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $file->refresh();
        $this->assertSame(['ru' => 'Спецификация'], $file->title);
        $this->assertSame('account', $file->access);
        $this->assertSame(2, $file->position);

        auth()->logout();

        $page = $this->get('http://localhost/ru/product/doc-router')->assertOk()->getContent();
        $this->assertStringContainsString('Спецификация', $page);
        $this->assertStringContainsString('maker.example', $page);
        $this->assertLessThan(strpos($page, 'Спецификация'), strpos($page, 'maker.example'), 'in the order the form left them');

        // For signed-in customers: a guest is sent to sign in, a customer gets it.
        $this->get($file->href())->assertRedirect(route('account.login'));
        $customer = User::factory()->create();
        $this->actingAs($customer)->get($file->href())->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename=datasheet.pdf');
        $this->assertSame(1, $file->fresh()->downloads);

        // A product nobody can reach takes its documents with it.
        $this->product->update(['status' => 'disabled']);
        $this->actingAs($customer)->get($file->href())->assertNotFound();
        $this->actingAs($this->admin)->get($file->href())->assertOk();
    }

    public function test_a_product_gone_for_good_takes_its_files(): void
    {
        $file = $this->upload('Manual.pdf');

        $this->product->delete();
        $this->assertNotNull(Attachment::find($file->id), 'the bin keeps them');

        $this->product->forceDelete();
        $this->assertNull(Attachment::find($file->id));
        Storage::disk('private')->assertMissing($file->path);
    }

    protected function upload(string $name): Attachment
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/attachments/product/'.$this->product->id.'/files', ['files' => [$this->pdf($name)]], ['Accept' => 'application/json'])->assertOk();

        return Attachment::query()->latest('id')->firstOrFail();
    }

    protected function pdf(string $name): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        return $this->file($name, str_pad($body, 1024, ' '));
    }

    /**
     * A real upload over real bytes. Not UploadedFile::fake(): a fake one
     * answers its type from its NAME, which is exactly what must not be
     * trusted — a test with it would pass a script called spec.pdf.
     */
    protected function file(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}

<?php

namespace Tests\Feature\Options;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Options\Configurator;
use Torgash\Options\Files;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionFile;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * An option the customer UPLOADS (PLAN §33.7): a layout, a logo, artwork.
 *
 * This is a public upload, so the statements worth pinning are the ones that
 * make it safe and the ones that make it useful — and they pull in opposite
 * directions. The bytes are kept exactly as they arrived, because the file IS
 * the thing being bought and re-encoding it destroys it; so the refusals have
 * to be made on what the bytes ARE rather than on what they were called, and
 * nothing may ever be written where a web server could be asked for it.
 */
class FileOptionTest extends TestCase
{
    protected Product $cards;

    protected OptionGroup $layout;

    protected function setUp(): void
    {
        parent::setUp();

        app(Groups::class)->flush();
        app(PriceRules::class)->flush();
        Storage::fake(OptionFile::DISK);

        $this->cards = Product::create([
            'sku' => 'CARDS', 'name' => ['en' => 'Business cards'], 'slug' => 'cards',
            'price' => 500_00, 'stock' => 100, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);

        $this->layout = OptionGroup::create([
            'product_id' => $this->cards->id,
            'admin_name' => 'layout',
            'name' => ['en' => 'Your layout'],
            'type' => 'file',
            'required' => true,
            'enabled' => true,
        ]);

        OptionChoice::create([
            'group_id' => $this->layout->id, 'enabled' => true, 'kind' => 'plain',
            'name' => ['en' => 'Layout'], 'price_rule' => 'fixed', 'amount' => 50_00,
        ]);

        app(Groups::class)->flush();
    }

    /** The ordinary path: a file becomes a token, and the token is the answer. */
    public function test_an_uploaded_file_is_stored_and_priced_into_the_line(): void
    {
        $this->addWith(UploadedFile::fake()->image('visitka.png'));

        $line = app(CartManager::class)->items()->first();
        $file = OptionFile::firstOrFail();

        $this->assertSame(550_00, $line->line_total, 'the product plus what the question costs');
        $this->assertSame('visitka.png', $file->original_name);
        $this->assertSame($file->token, $line->config[Configurator::KEY][(string) $this->layout->id][0]['f']);
        $this->assertStringContainsString('visitka.png', $line->parts[0]['label']);
        Storage::disk(OptionFile::DISK)->assertExists($file->path);
    }

    /**
     * The one that matters. A name is what a stranger typed; the extension
     * comes from what the bytes say the file is, so a script called `.png` has
     * no extension the shop accepts and never reaches the disk.
     */
    public function test_a_script_wearing_an_image_name_is_refused(): void
    {
        // Asked of `Files` directly rather than over HTTP: the test client's
        // own fake upload answers `getMimeType()` from the NAME, which is the
        // one thing this test exists to disbelieve — going through it would
        // pass whatever the code did.
        $result = app(Files::class)->store(
            $this->layout,
            $this->realFile('visitka.png', "<?php return 'not a picture';"),
        );

        $this->assertNull($result['file']);
        $this->assertNotNull($result['error']);
        $this->assertSame(0, OptionFile::count(), 'nothing was stored');
        $this->assertSame([], Storage::disk(OptionFile::DISK)->allFiles(), 'and nothing reached the disk');
    }

    /** The same, for the disguise that gets past a naive extension check. */
    public function test_a_double_extension_does_not_smuggle_anything_through(): void
    {
        $result = app(Files::class)->store(
            $this->layout,
            $this->realFile('logo.php.png', "<?php return 2;"),
        );

        $this->assertNull($result['file']);
        $this->assertSame([], Storage::disk(OptionFile::DISK)->allFiles());
    }

    /** The shop's list is a list, and what is not on it is refused rather than tidied. */
    public function test_a_format_the_question_does_not_take_is_refused(): void
    {
        $this->layout->update(['file_types' => 'pdf']);
        app(Groups::class)->flush();

        $this->addWith(UploadedFile::fake()->image('visitka.png'));

        $this->assertSame(0, OptionFile::count());
    }

    /** Too big is refused before anything is written. */
    public function test_a_file_over_the_limit_is_refused(): void
    {
        $this->layout->update(['max_kb' => 10]);
        app(Groups::class)->flush();

        $this->addWith(UploadedFile::fake()->image('huge.png')->size(200));

        $this->assertSame(0, OptionFile::count());
    }

    /** A required file question refuses the purchase when nothing was attached. */
    public function test_a_required_file_question_refuses_an_empty_answer(): void
    {
        $this->post('http://localhost/cart/add/'.$this->cards->slug, ['qty' => 1])->assertOk();

        $this->assertTrue(app(CartManager::class)->items()->isEmpty());
    }

    /**
     * Re-opening the form to change something else must not throw the artwork
     * away: the token travels in a hidden field, and not re-uploading keeps
     * the file.
     */
    public function test_the_token_alone_keeps_the_file_on_a_second_submission(): void
    {
        $this->addWith(UploadedFile::fake()->image('visitka.png'));
        $token = OptionFile::firstOrFail()->token;

        app(CartManager::class)->clear();

        $this->post('http://localhost/cart/add/'.$this->cards->slug, [
            'qty' => 1,
            'config' => [Configurator::KEY => [(string) $this->layout->id => ['token' => $token]]],
        ])->assertOk();

        $line = app(CartManager::class)->items()->first();

        $this->assertNotNull($line);
        $this->assertSame($token, $line->config[Configurator::KEY][(string) $this->layout->id][0]['f']);
        $this->assertSame(1, OptionFile::count(), 'no second copy was made');
    }

    /** A file whose bytes have gone is not an answer, and says so rather than selling. */
    public function test_a_file_that_has_been_swept_refuses_the_purchase(): void
    {
        $this->addWith(UploadedFile::fake()->image('visitka.png'));
        $token = OptionFile::firstOrFail()->token;

        OptionFile::firstOrFail()->purge();
        app(CartManager::class)->clear();

        $this->post('http://localhost/cart/add/'.$this->cards->slug, [
            'qty' => 1,
            'config' => [Configurator::KEY => [(string) $this->layout->id => ['token' => $token]]],
        ])->assertOk();

        $this->assertTrue(app(CartManager::class)->items()->isEmpty());
    }

    /** Only a signature or a manager gets the bytes back. */
    public function test_the_file_is_served_to_staff_and_to_a_signature_and_to_nobody_else(): void
    {
        $this->addWith(UploadedFile::fake()->image('visitka.png'));
        $token = OptionFile::firstOrFail()->token;

        $plain = 'http://localhost/options/file/'.$token;

        // A stranger is told the address does not exist — never 403, which
        // would confirm that the token is a real one.
        $this->get($plain)->assertNotFound();

        $this->get(URL::signedRoute('options.file', ['file' => $token]))
            ->assertOk()
            ->assertDownload('visitka.png');

        $this->actingAs($this->admin())->get($plain)->assertOk();

        // A token nobody issued is the same answer as a token without a
        // signature.
        $this->actingAs($this->admin())
            ->get('http://localhost/options/file/'.str_repeat('a', 40))
            ->assertNotFound();
    }

    /**
     * An order keeps its file; a basket nobody came back to does not. Both
     * halves of the same statement, and the sweep is what makes the disk
     * bounded.
     */
    public function test_the_sweep_spares_what_an_order_bought(): void
    {
        $this->addWith(UploadedFile::fake()->image('bought.png'));
        $this->placeOrder();

        $bought = OptionFile::firstOrFail();
        $this->assertNotNull($bought->order_id, 'placing the order claimed the file');

        // A second file, in a basket nobody came back to.
        app(CartManager::class)->clear();
        $this->addWith(UploadedFile::fake()->image('abandoned.png'));
        $abandoned = OptionFile::where('original_name', 'abandoned.png')->firstOrFail();
        $abandoned->update(['created_at' => now()->subDays(90)]);

        $this->assertSame(1, app(Files::class)->sweep());

        $this->assertNull(OptionFile::find($abandoned->id));
        Storage::disk(OptionFile::DISK)->assertMissing($abandoned->path);
        $this->assertNotNull(OptionFile::find($bought->id), 'a file an order holds is kept');
        Storage::disk(OptionFile::DISK)->assertExists($bought->path);
    }

    /** Whatever a shop types, this engine will not take a script. */
    public function test_the_refused_list_cannot_be_opened_up_by_a_setting(): void
    {
        $this->layout->update(['file_types' => 'php,svg,html,png']);
        app(Groups::class)->flush();

        $types = app(Files::class)->types($this->layout->fresh());

        $this->assertSame(['png'], $types);
    }

    /** The question is drawn, and the form that holds it can carry a file at all. */
    public function test_the_product_page_offers_a_file_field_in_a_multipart_form(): void
    {
        $body = $this->get('http://localhost/product/'.$this->cards->slug)->assertOk()->getContent();

        $this->assertStringContainsString('config[options]['.$this->layout->id.'][file]', $body);
        $this->assertStringContainsString('multipart/form-data', $body);
    }

    /**
     * A genuinely real upload: bytes on disk, the mime left to be worked out
     * from them.
     *
     * `UploadedFile::fake()` will not do for the refusal tests — its testing
     * subclass answers `getMimeType()` from the NAME, which is precisely the
     * thing under test here, so a fake would pass whatever the code did.
     *
     * The contents are a harmless PHP file and deliberately not a web shell:
     * a real one is what these refusals are FOR, but writing the usual
     * `system($_GET[…])` line into the system temp directory sets off the
     * machine's antivirus (measured — it did). What the rule actually turns on
     * is that the bytes are not an image, and `<?php return …;` is as
     * un-image-like as the nastier version.
     */
    protected function realFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'opt');
        file_put_contents($path, $contents);

        // Tidied up at the end of the test rather than left in temp for a
        // scanner to find later.
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        // `test: true` only says "do not insist this came through PHP's upload
        // machinery"; the mime is still read off the bytes.
        return new UploadedFile($path, $name, null, null, true);
    }

    protected function addWith(UploadedFile $file)
    {
        // The file rides the add-to-cart POST, exactly as the form sends it —
        // there is no upload endpoint to call instead, which is the whole of
        // rule 1 in `Files`.
        return $this->post('http://localhost/cart/add/'.$this->cards->slug, [
            'qty' => 1,
            'config' => [Configurator::KEY => [(string) $this->layout->id => ['file' => $file]]],
        ]);
    }

    protected function placeOrder(): Order
    {
        $this->post('/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ]);

        return Order::latest('id')->firstOrFail();
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}

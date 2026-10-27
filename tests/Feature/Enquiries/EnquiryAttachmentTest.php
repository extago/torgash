<?php

namespace Tests\Feature\Enquiries;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Enquiries\Attachments;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\PostMessage;
use Torgash\Media\ImageProcessor;

/**
 * Photographs on an enquiry — the "фото брака" the returns plan deferred to
 * this point.
 *
 * Almost everything worth pinning here is about the fact that this is a PUBLIC
 * upload. There is no endpoint of its own (files ride the POST that was already
 * happening, so the form's own rate limit counts them); a file is judged by
 * what it IS and never by its name; the disk is private, so a photograph of
 * somebody's kitchen is not served off /storage by sequential id; and a file
 * that will not do never costs the words it came with.
 */
class EnquiryAttachmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('form:enquiry:ip:127.0.0.1');
        RateLimiter::clear('form:enquiry:id:seller@example.test');

        app(SettingsManager::class)->set('torgash.enquiries', 'attachments_max', 3);
    }

    // ------------------------------------------------------------ the upload

    public function test_a_photo_sent_with_the_form_lands_on_the_enquiry(): void
    {
        $this->post('http://localhost/enquiry', $this->payload([
            'attachments' => [UploadedFile::fake()->image('fault.jpg', 800, 600)],
        ]))->assertRedirect();

        $enquiry = Enquiry::latest('id')->firstOrFail();
        $files = Attachments::of($enquiry);

        $this->assertCount(1, $files);
        // Not the public disk: this is somebody's kitchen table, not a
        // catalogue photo, and /storage is addressed by sequential id.
        $this->assertSame(Attachments::DISK, $files->first()->disk);
        $this->assertFileExists($files->first()->getPath());
    }

    /**
     * The one that matters: a script calling itself a photo is judged by what
     * it IS, so it never reaches disk at all.
     *
     * Built as a real temporary file rather than with `UploadedFile::fake()`,
     * deliberately — the fake takes its mime from the EXTENSION, which is the
     * exact assumption under test, so a fake would have proved nothing.
     */
    public function test_a_script_pretending_to_be_a_photo_is_refused(): void
    {
        $this->post('http://localhost/enquiry', $this->payload([
            'attachments' => [$this->realFile('shell.php.jpg', '<?php echo 1;')],
        ]))->assertRedirect();

        $enquiry = Enquiry::latest('id')->firstOrFail();

        $this->assertCount(0, Attachments::of($enquiry));
        // And the enquiry itself survived: the words are the valuable half, and
        // a public form that 500s over a file has thrown them away.
        $this->assertSame('Сломался при первом включении', $enquiry->answer('message'));
        $this->assertNotNull(session('warning'), 'the sender was not told the file was dropped');
    }

    /**
     * The same trap one layer in: bytes that SNIFF as an image and are not one
     * — a truncated upload, or a header with rubbish behind it. This used to
     * pass the mime check, get stored, and then throw out of the thumbnail
     * pass, which is a 500 on the form somebody was standing in front of.
     */
    public function test_a_file_that_only_looks_like_an_image_is_refused_without_a_500(): void
    {
        // A real JPEG's first bytes, and nothing else that is a JPEG.
        $this->post('http://localhost/enquiry', $this->payload([
            'attachments' => [$this->realFile('truncated.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 256))],
        ]))->assertRedirect();

        $enquiry = Enquiry::latest('id')->firstOrFail();

        $this->assertCount(0, Attachments::of($enquiry));
        $this->assertSame('Сломался при первом включении', $enquiry->answer('message'));
    }

    public function test_more_photos_than_the_shop_allows_are_refused_and_the_rest_are_kept(): void
    {
        app(SettingsManager::class)->set('torgash.enquiries', 'attachments_max', 2);

        $this->post('http://localhost/enquiry', $this->payload([
            'attachments' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
                UploadedFile::fake()->image('three.jpg'),
            ],
        ]))->assertRedirect();

        $this->assertCount(2, Attachments::of(Enquiry::latest('id')->firstOrFail()));
    }

    /** Zero means off, and then the form does not offer the field either. */
    public function test_attachments_can_be_turned_off_entirely(): void
    {
        app(SettingsManager::class)->set('torgash.enquiries', 'attachments_max', 0);

        $this->post('http://localhost/enquiry', $this->payload([
            'attachments' => [UploadedFile::fake()->image('fault.jpg')],
        ]))->assertRedirect();

        $this->assertCount(0, Attachments::of(Enquiry::latest('id')->firstOrFail()));
    }

    /**
     * A reply carries them too, and the count is per row: a thread where every
     * message brings three photos is not a way round the limit, but it is not
     * capped by what the enquiry already holds either.
     */
    public function test_a_reply_carries_its_own_photos(): void
    {
        $enquiry = $this->submit();

        app(PostMessage::class)->fromStaff(
            $enquiry,
            $this->admin(),
            'Пришлите ещё одно фото, пожалуйста.',
            [UploadedFile::fake()->image('example.jpg')],
        );

        $message = $enquiry->messages()->latest('id')->firstOrFail();

        $this->assertCount(1, Attachments::of($message));
        $this->assertCount(1, Attachments::of($enquiry), 'the enquiry’s own photo was counted against the reply');
    }

    // ------------------------------------------------------------ reading one

    public function test_a_file_is_served_to_staff_and_to_a_signed_link_but_not_to_a_stranger(): void
    {
        $enquiry = $this->submit();
        $media = Attachments::of($enquiry)->first();

        // Nobody, no signature: not 403 — somebody else's must not read
        // differently from nothing at all.
        $this->get('http://localhost/enquiry-file/'.$media->id)->assertNotFound();

        // The signature says WHICH file, not who is asking — which is how a
        // guest reading their order by its key sees the photo they sent.
        $this->get(URL::signedRoute('enquiries.file', ['media' => $media->id]))->assertOk();

        $this->actingAs($this->admin())
            ->get('http://localhost/enquiry-file/'.$media->id)
            ->assertOk();
    }

    /** The account that owns the thread, with no signature at all. */
    public function test_the_owner_of_the_thread_may_read_its_files(): void
    {
        $customer = User::create(['name' => 'Иван', 'email' => 'buyer@example.test', 'password' => 'secret123']);

        $enquiry = $this->submit();
        $enquiry->update(['user_id' => $customer->id]);

        $media = Attachments::of($enquiry)->first();

        $this->actingAs($customer)
            ->get('http://localhost/enquiry-file/'.$media->id)
            ->assertOk();

        $stranger = User::create(['name' => 'Пётр', 'email' => 'other@example.test', 'password' => 'secret123']);

        $this->actingAs($stranger)
            ->get('http://localhost/enquiry-file/'.$media->id)
            ->assertNotFound();
    }

    /**
     * Only this collection is served by this route. A product photo has its own
     * public URL and must not become readable through a signature meant for a
     * private one.
     */
    public function test_the_route_serves_only_enquiry_attachments(): void
    {
        $product = Product::create([
            'sku' => 'ATT-1',
            'name' => ['en' => 'A thing'],
            'slug' => 'a-thing-att',
            'price' => 10_00,
            'status' => 'active',
        ]);

        $media = app(ImageProcessor::class)
            ->addUpload($product, UploadedFile::fake()->image('product.jpg'));

        $this->actingAs($this->admin())
            ->get(URL::signedRoute('enquiries.file', ['media' => $media->id]))
            ->assertNotFound();
    }

    // -------------------------------------------------------------- fixtures

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    /**
     * An upload whose mime is SNIFFED from the bytes, the way a real one's is.
     * `UploadedFile::fake()` derives it from the extension instead, which is
     * fine for a genuine photo and useless for testing a disguised file.
     */
    protected function realFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);

        // `$test: true` skips the "was this really uploaded" check; the mime is
        // left null so Symfony detects it from the file itself.
        return new UploadedFile($path, $name, null, null, true);
    }

    /** An enquiry with one photo on it. */
    protected function submit(): Enquiry
    {
        $this->post('http://localhost/enquiry', $this->payload([
            'attachments' => [UploadedFile::fake()->image('fault.jpg', 800, 600)],
        ]))->assertRedirect();

        return Enquiry::latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'message',
            'name' => 'Иван',
            'email' => 'seller@example.test',
            'message' => 'Сломался при первом включении',
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ], $overrides);
    }
}

<?php

namespace Tests\Feature\Media;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Media\Content;
use Torgash\Media\MediaOwners;

/**
 * Images that live INSIDE a text.
 *
 * The feature is small; the decisions around it are not, and they are what is
 * pinned here. A content image is not a gallery photo (no thumbnails, no
 * order, no caption of its own), the two collections cannot reach each other's
 * files, nothing is ever detached behind the author's back, an unsaved record
 * offers none of it — and the whole mechanism is reusable, which is asserted
 * by plugging a SECOND model from another module into it and getting a working
 * endpoint out.
 */
class ContentImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.media', 'content_webp', false);
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    protected function product(string $sku = 'CNT-1'): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => 'Described'],
            'slug' => 'described-'.strtolower($sku),
            'price' => 10_00,
            'stock' => 1,
            'status' => 'active',
        ]);
    }

    /** A real JPEG, because everything here reads the BYTES rather than the name. */
    protected function image(int $width = 900, int $height = 500, string $format = 'jpg'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 245));
        imagefilledrectangle($image, 10, 10, (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 20, 30, 50));

        $path = tempnam(sys_get_temp_dir(), 'tg').'.'.$format;

        match ($format) {
            'png' => imagepng($image, $path),
            'gif' => imagegif($image, $path),
            default => imagejpeg($image, $path, 88),
        };

        return new UploadedFile($path, 'diagram.'.$format, null, null, true);
    }

    protected function upload(Product $product, ?UploadedFile $file = null)
    {
        return $this->actingAs($this->admin())->post(
            'http://localhost/admin/media/product/'.$product->id.'/content',
            ['images' => [$file ?? $this->image()]],
        );
    }

    // ------------------------------------------------------- what it stores

    /**
     * The one difference that costs disk: a content image gets no conversions.
     *
     * Four thumbnails of a size chart are four files nothing will ever ask
     * for, and the rule lives in `Gallery::conversions()` rather than in each
     * model — so this is the test that would fail if a model started
     * registering its own.
     */
    public function test_an_image_in_a_text_is_stored_once_and_thumbnailed_never(): void
    {
        $product = $this->product();

        $response = $this->upload($product)->assertOk();

        $this->assertCount(1, $response->json('items'));

        $media = $product->fresh()->getMedia(Content::COLLECTION);

        $this->assertCount(1, $media);
        $this->assertSame([], $media->first()->generated_conversions ?? []);

        // ...and the dimensions ARE measured, because the panel prints them and
        // an inserted image needs width/height on the tag.
        $this->assertSame(900, $media->first()->getCustomProperty('width'));
        $this->assertSame(500, $media->first()->getCustomProperty('height'));

        $item = $response->json('items.0');

        $this->assertSame(900, $item['width']);
        $this->assertSame('diagram.jpg', $item['name']);
        $this->assertGreaterThan(0, $item['size']);
    }

    /** The gallery keeps its thumbnails — this changed nothing for it. */
    /**
     * A text from an import or an older editor carries a bare <img src>: the
     * library's own picture gets its width and height when printed, so the
     * words under it do not jump; somebody else's picture is left alone.
     */
    public function test_a_bare_picture_of_ours_is_printed_with_its_size(): void
    {
        $product = $this->product();
        $this->upload($product)->assertOk();
        $media = $product->fresh()->getMedia(Content::COLLECTION)->first();

        $html = (string) safe_html('<p>x</p><img src="'.$media->getUrl().'" alt="a"><img src="https://elsewhere.test/9/'.$media->file_name.'" alt="b"><img src="'.$media->getUrl().'" width="10" alt="c">');

        $this->assertStringContainsString('<img width="900" height="500" src="'.$media->getUrl().'" alt="a"', $html);
        $this->assertStringNotContainsString('width="900" height="500" src="https://elsewhere.test', $html, 'an id that is not this file');
        $this->assertSame(1, substr_count($html, 'width="900"'), 'a picture with numbers keeps its own');
    }

    public function test_a_gallery_photo_still_gets_its_thumbnails(): void
    {
        $product = $this->product('CNT-GAL');

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/media/product/'.$product->id.'/upload', ['images' => [$this->image(600, 600)]])
            ->assertOk();

        $media = $product->fresh()->getMedia('gallery')->first();

        $this->assertNotNull($media);
        $this->assertNotEmpty($media->generated_conversions ?? []);
    }

    /**
     * Two collections on one record, and neither endpoint can touch the
     * other's files. The gallery's delete on a content image is a 404, not a
     * silent no-op — an id that belongs somewhere else is a mistake worth
     * saying out loud.
     */
    public function test_the_text_and_the_gallery_cannot_reach_each_others_files(): void
    {
        $product = $this->product('CNT-BOTH');

        $this->upload($product)->assertOk();
        $this->actingAs($this->admin())
            ->post('http://localhost/admin/media/product/'.$product->id.'/upload', ['images' => [$this->image()]])
            ->assertOk();

        $product = $product->fresh();
        $inText = $product->getMedia(Content::COLLECTION)->first();
        $inGallery = $product->getMedia('gallery')->first();

        $this->assertNotSame($inText->id, $inGallery->id);

        $this->actingAs($this->admin())
            ->delete('http://localhost/admin/media/product/'.$product->id.'/content/'.$inGallery->id)
            ->assertNotFound();

        $this->actingAs($this->admin())
            ->delete('http://localhost/admin/media/product/'.$product->id.'/'.$inText->id)
            ->assertNotFound();

        // Both still there.
        $this->assertCount(1, $product->fresh()->getMedia(Content::COLLECTION));
        $this->assertCount(1, $product->fresh()->getMedia('gallery'));
    }

    /**
     * Nothing is swept up on save. An image cut out of a paragraph may be going
     * back in after lunch, so the file stays until somebody says otherwise —
     * the panel marks it and a person decides.
     */
    public function test_a_file_the_text_no_longer_mentions_is_kept(): void
    {
        $product = $this->product('CNT-KEEP');
        $url = $this->upload($product)->json('items.0.url');

        $this->actingAs($this->admin())->put(
            'http://localhost/admin/catalog/products/'.$product->id,
            $this->productForm($product, '<p>No picture here at all.</p>'),
        )->assertRedirect();

        $this->assertStringNotContainsString($url, (string) $product->fresh()->t('description', 'en'));
        $this->assertCount(1, $product->fresh()->getMedia(Content::COLLECTION));
    }

    /**
     * Ownership, and the reason these live on the record instead of in a
     * shop-wide file manager: they go when it goes. Both halves, because the
     * product is deleted into a trash first — restoring it must bring its
     * pictures back with the text that references them.
     */
    public function test_the_pictures_belong_to_the_record(): void
    {
        $product = $this->product('CNT-OWN');
        $this->upload($product)->assertOk();

        $product->delete();

        $this->assertCount(1, $product->fresh()->getMedia(Content::COLLECTION), 'a trashed product keeps them');

        $product->fresh()->forceDelete();

        $this->assertSame(0, Media::query()
            ->where('collection_name', Content::COLLECTION)
            ->where('model_id', $product->id)
            ->count());
    }

    // ---------------------------------------------------------- who is offered

    /** No record, no id, nowhere to put a file — so the editor is told nothing. */
    public function test_an_unsaved_record_is_offered_nothing(): void
    {
        $this->actingAs($this->admin());

        $this->assertNull(Content::context(new Product));
        $this->assertNull(Content::context(null));
    }

    public function test_a_saved_record_is_told_where_to_upload(): void
    {
        $product = $this->product('CNT-CTX');

        $this->actingAs($this->admin());

        $context = Content::context($product);

        $this->assertSame(url('/admin/media/product/'.$product->id.'/content'), $context['endpoint']);
        $this->assertArrayHasKey('allow_remote', $context);
    }

    /** A model nobody registered as keeping images in its text gets no panel. */
    public function test_a_model_that_did_not_ask_for_this_is_not_offered_it(): void
    {
        $enquiry = Enquiry::create(['kind' => 'question', 'name' => 'Иван', 'phone' => '+380671234567']);

        $this->actingAs($this->admin());

        $this->assertNull(Content::context($enquiry));

        // ...and its endpoint refuses rather than inventing a collection.
        $this->actingAs($this->admin())
            ->get('http://localhost/admin/media/enquiry/'.$enquiry->id.'/content')
            ->assertNotFound();
    }

    /**
     * The claim the owner cared about: the same mechanism will do for pages and
     * for a blog. Proved by plugging in a SECOND model — from another module,
     * with its own ability — and getting a working endpoint out of one
     * registration and nothing else.
     */
    public function test_a_second_model_plugs_in_with_one_registration(): void
    {
        $enquiry = Enquiry::create(['kind' => 'question', 'name' => 'Иван', 'phone' => '+380671234567']);

        app(MediaOwners::class)->register('enquiry_text', Enquiry::class, 'enquiries.manage', content: true);

        $this->actingAs($this->admin());

        $this->assertSame('enquiry_text', app(MediaOwners::class)->contentKeyFor($enquiry));
        $this->assertStringContainsString('/enquiry_text/'.$enquiry->id.'/content', Content::context($enquiry)['endpoint']);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/media/enquiry_text/'.$enquiry->id.'/content', ['images' => [$this->image()]])
            ->assertOk()
            ->assertJsonCount(1, 'items');

        $this->assertCount(1, $enquiry->fresh()->getMedia(Content::COLLECTION));
    }

    /**
     * Pages keep pictures in their text through the `media.owners` hook —
     * pages never names this module's classes, and the button appears on its
     * form exactly as on a product's, after the first save.
     */
    public function test_a_page_keeps_pictures_in_its_text(): void
    {
        $page = \Torgash\Pages\Models\Page::create(['title' => ['en' => 'With a diagram'], 'slug' => 'with-a-diagram', 'body' => ['en' => '<p>x</p>'], 'enabled' => true, 'in_sitemap' => true]);

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/pages/'.$page->id.'/edit')
            ->assertOk()
            ->assertSee('data-tiptap-command="image"', false)
            ->assertSee('/admin/media/page/'.$page->id.'/content', false);

        $this->post('http://localhost/admin/media/page/'.$page->id.'/content', ['images' => [$this->image()]])
            ->assertOk()->assertJsonCount(1, 'items');
        $this->assertCount(1, $page->fresh()->getMedia(Content::COLLECTION));

        // ...and they go with the page.
        $page->delete();
        $this->assertSame(0, \Spatie\MediaLibrary\MediaCollections\Models\Media::where('model_type', $page->getMorphClass())->where('model_id', $page->id)->count());
    }

    /** The ability is the record's own, so the endpoint is not a way round it. */
    public function test_somebody_without_the_ability_gets_nothing_and_can_do_nothing(): void
    {
        $product = $this->product('CNT-ACL');
        $customer = User::create(['name' => 'Иван', 'email' => 'buyer-content@example.test', 'password' => 'secret123']);

        $this->actingAs($customer);

        $this->assertNull(Content::context($product));

        $this->post('http://localhost/admin/media/product/'.$product->id.'/content', ['images' => [$this->image()]])
            ->assertForbidden();
    }

    // ------------------------------------------------------------- the form

    /**
     * End to end through the actual screen: core passes the record, the editor
     * asks, the media module answers, and the button exists — or does not,
     * before the first save.
     */
    public function test_the_product_form_offers_the_panel_only_after_the_first_save(): void
    {
        $product = $this->product('CNT-FORM');

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/catalog/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertSee('data-tiptap-command="image"', false)
            ->assertSee('/admin/media/product/'.$product->id.'/content', false);

        $this->get('http://localhost/admin/catalog/products/create')
            ->assertOk()
            ->assertSee('data-tiptap', false)
            ->assertDontSee('data-tiptap-command="image"', false)
            ->assertDontSee('data-tiptap-images', false);
    }

    // -------------------------------------------------------------- the WebP

    /**
     * The setting the plan asked to decide before starting: off by default, and
     * when it is on it applies to a text image and never to an animation.
     */
    public function test_webp_is_opt_in_and_never_touches_a_gif(): void
    {
        $product = $this->product('CNT-WEBP');

        $this->upload($product, $this->image(400, 300, 'png'))->assertOk();
        $this->assertSame('png', pathinfo($product->fresh()->getMedia(Content::COLLECTION)->first()->file_name, PATHINFO_EXTENSION));

        app(SettingsManager::class)->set('torgash.media', 'content_webp', true);

        $product = $this->product('CNT-WEBP-2');
        $this->upload($product, $this->image(400, 300, 'png'))->assertOk();
        $this->assertSame('webp', pathinfo($product->fresh()->getMedia(Content::COLLECTION)->first()->file_name, PATHINFO_EXTENSION));

        // An animated GIF re-encoded to WebP loses its animation, so it is left
        // alone whatever the setting says.
        $product = $this->product('CNT-WEBP-3');
        $this->upload($product, $this->image(400, 300, 'gif'))->assertOk();
        $this->assertSame('gif', pathinfo($product->fresh()->getMedia(Content::COLLECTION)->first()->file_name, PATHINFO_EXTENSION));

        // ...and a GALLERY photo is not affected by this setting at all.
        $product = $this->product('CNT-WEBP-4');
        $this->actingAs($this->admin())
            ->post('http://localhost/admin/media/product/'.$product->id.'/upload', ['images' => [$this->image(400, 300, 'png')]])
            ->assertOk();
        $this->assertSame('png', pathinfo($product->fresh()->getMedia('gallery')->first()->file_name, PATHINFO_EXTENSION));
    }

    /** The minimum a product update needs, so the description can be changed. */
    protected function productForm(Product $product, string $description): array
    {
        return [
            'sku' => $product->sku,
            'name' => $product->t('name', 'en'),
            'slug' => $product->slug,
            'price' => 10,
            'stock' => 1,
            'status' => 'active',
            'description' => $description,
        ];
    }
}

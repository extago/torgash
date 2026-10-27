<?php

namespace Tests\Feature\Blog;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Torgash\Blog\Models\Post;
use Torgash\Blog\Models\Rubric;
use Torgash\Catalog\Models\Product;

/**
 * The blog (PLAN §47): rubrics as plates, a feed with "show more", an article
 * with its contents built from its headings and products in its text, a like
 * per browser, "read also", and what is not published staying out of sight.
 */
class BlogTest extends TestCase
{
    protected User $admin;

    protected Rubric $phones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->phones = Rubric::create(['name' => ['en' => 'Phones'], 'slug' => 'phones', 'icon' => 'smartphone', 'color' => '#2563eb', 'enabled' => true]);
        Product::create(['sku' => 'BL-PHONE', 'name' => ['en' => 'Vernee Thor'], 'slug' => 'bl-phone', 'price' => 200_00, 'stock' => 5, 'status' => 'active']);
    }

    public function test_an_article_written_in_the_admin_reads_on_the_site(): void
    {
        $this->actingAs($this->admin)->post('http://localhost/admin/blog', [
            'title' => 'Twelve budget phones',
            'lead' => 'From budget to flagship.',
            'body' => '<h2>Vernee Thor</h2><p>A long battery.</p><p>{product:BL-PHONE}</p><h3>Camera</h3><p>Fine.</p><h2>Elephone</h2><p>Also good.</p>',
            'status' => 'published',
            'rubrics' => [$this->phones->id],
            'products' => "BL-PHONE\nNOPE-1",
            'comments_enabled' => 1,
        ])->assertRedirect()->assertSessionHas('warning');

        $post = Post::where('slug', 'twelve-budget-phones')->firstOrFail();
        $this->assertSame($this->admin->name, $post->author_name);
        $this->assertNotNull($post->published_at);

        $this->get('http://localhost/blog')->assertOk()
            ->assertSee('Twelve budget phones')->assertSee('Phones')->assertSee('From budget to flagship.');

        $this->get('http://localhost/blog/twelve-budget-phones')->assertOk()
            ->assertSee('id="vernee-thor"', false)
            ->assertSee('href="#camera"', false)
            ->assertSee(__('In this article'))
            ->assertSee(__('Open in the catalogue'))
            ->assertSee(__('Products in this article'))
            ->assertDontSee('{product:BL-PHONE}');

        $this->get('http://localhost/blog/rubric/phones')->assertOk()->assertSee('Twelve budget phones');
        $this->get('http://localhost/blog/twelve-budget-phones.md')->assertOk()->assertSee('# Twelve budget phones');
        $this->get('http://localhost/sitemap.xml')->assertOk()->assertSee('/blog/twelve-budget-phones');
    }

    public function test_drafts_and_scheduled_articles_stay_out_of_sight_and_hidden_ones_open_by_address(): void
    {
        $this->post_('Draft one', ['status' => 'draft']);
        $this->post_('Next week', ['status' => 'published', 'published_at' => now()->addWeek()]);
        $this->post_('Campaign only', ['status' => 'hidden']);

        $this->get('http://localhost/blog')->assertOk()
            ->assertDontSee('Draft one')->assertDontSee('Next week')->assertDontSee('Campaign only');
        $this->get('http://localhost/blog/draft-one')->assertNotFound();
        $this->get('http://localhost/blog/next-week')->assertNotFound();
        $this->get('http://localhost/blog/campaign-only')->assertOk()->assertSee('Campaign only');
    }

    /** The rubrics are a column with counts that leave out what a reader cannot see. */
    public function test_the_rubrics_column_counts_what_the_feed_lists(): void
    {
        $this->post_('Phone one');
        $this->post_('Phone two');
        $this->post_('Phone draft', ['status' => 'draft']);

        $html = $this->get('http://localhost/blog/rubric/phones')->assertOk()->getContent();
        $nav = substr($html, (int) strpos($html, 'aria-label="Rubrics"'));
        $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));

        $this->assertStringContainsString('All articles', $nav);
        $this->assertMatchesRegularExpression('#aria-current="page"[^>]*>.*?Phones.*?>2</span>#s', $nav);
    }

    public function test_a_like_per_browser_and_a_second_press_takes_it_back(): void
    {
        $post = $this->post_('Liked one');

        $first = $this->post('http://localhost/blog/liked-one/like', [], ['HX-Request' => 'true'])->assertOk();
        $this->assertSame(1, $post->fresh()->likes_count);

        $cookie = $first->getCookie(\Torgash\Blog\Likes::COOKIE);
        $this->withCookie(\Torgash\Blog\Likes::COOKIE, $cookie->getValue())
            ->post('http://localhost/blog/liked-one/like', [], ['HX-Request' => 'true'])->assertOk();
        $this->assertSame(0, $post->fresh()->likes_count, 'the same browser took it back');

        // A crawler likes nothing.
        $this->post('http://localhost/blog/liked-one/like', [], ['HX-Request' => 'true', 'User-Agent' => 'Googlebot/2.1'])->assertOk();
        $this->assertSame(0, $post->fresh()->likes_count);
    }

    public function test_show_more_brings_the_next_page_of_cards(): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.blog', 'per_page', 2);
        foreach (['First', 'Second', 'Third'] as $i => $title) {
            $this->post_($title, ['published_at' => now()->subDays(3 - $i)]);
        }

        $this->get('http://localhost/blog')->assertOk()->assertSee('Third')->assertSee('Second')->assertDontSee('>First<', false)->assertSee(__('Show more'));
        $this->get('http://localhost/blog?page=2&more=1', ['HX-Request' => 'true'])->assertOk()
            ->assertSee('First')->assertDontSee('<header', false);
    }

    public function test_a_renamed_article_leaves_a_redirect(): void
    {
        $post = $this->post_('Old name');
        $post->update(['slug' => 'new-name']);

        $this->get('http://localhost/blog/old-name')->assertRedirect('http://localhost/blog/new-name');
    }

    public function test_the_admin_screens_open(): void
    {
        $post = $this->post_('Admin one');
        $this->actingAs($this->admin);

        $this->get('http://localhost/admin/blog')->assertOk()->assertSee('Admin one');
        $this->get('http://localhost/admin/blog/create')->assertOk();
        $this->get('http://localhost/admin/blog/'.$post->id.'/edit')->assertOk()->assertSee(__('Cover'));
        $this->get('http://localhost/admin/blog/rubrics')->assertOk()->assertSee('Phones');
        $this->get('http://localhost/admin/blog/rubrics/'.$this->phones->id.'/edit')->assertOk();
        $this->post('http://localhost/admin/blog/rubrics', ['name' => 'Gaming', 'icon' => 'gamepad-2', 'color' => '#16a34a', 'enabled' => 1])->assertRedirect();
        $this->assertSame('gamepad-2', Rubric::where('slug', 'gaming')->value('icon'));
    }

    public function test_the_cover_is_one_picture_and_a_new_one_replaces_it(): void
    {
        $post = $this->post_('Covered');
        $this->actingAs($this->admin);
        $url = 'http://localhost/admin/media/blog_post/'.$post->id.'/upload';

        // A batch of two still leaves one: the loader takes the first file.
        $this->post($url, ['images' => [$this->jpeg('first.jpg'), $this->jpeg('extra.jpg')]])->assertOk();
        $this->assertSame(['first.jpg'], $post->fresh()->getMedia('gallery')->pluck('file_name')->all());

        $this->post($url, ['images' => [$this->jpeg('second.jpg')]])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'second.jpg');

        // A refused upload keeps the cover the article had.
        $junk = UploadedFile::fake()->create('notes.txt', 1, 'text/plain');
        $this->post($url, ['images' => [$junk]])->assertStatus(422)->assertJsonPath('items.0.name', 'second.jpg');

        $this->get('http://localhost/admin/blog/'.$post->id.'/edit')->assertOk()->assertSee(__('Replace the picture'));
    }

    public function test_a_gallery_in_the_text_reaches_the_page_as_images(): void
    {
        $gallery = '<div data-gallery=""><img src="/storage/1/a.jpg" alt="Box" width="900" height="600" loading="lazy">'
            .'<img src="/storage/1/b.jpg" alt="Phone" width="900" height="600" loading="lazy"></div>';
        $this->post_('Unboxing', ['body' => ['en' => '<p>What is inside.</p>'.$gallery]]);

        $this->get('http://localhost/blog/unboxing')->assertOk()
            ->assertSee('data-gallery', false)->assertSee('src="/storage/1/b.jpg"', false)
            // The kit the storefront script builds it with.
            ->assertSee('data-content-gallery-kit', false)->assertSee('content-gallery.mjs', false);

        $this->get('http://localhost/blog/unboxing.md')->assertOk()->assertSee('/storage/1/a.jpg');
    }

    /** The block on any page: the newest large, the rest beside it, nothing unlisted — and nothing at all of an empty rubric. */
    public function test_the_latest_articles_block_shows_the_newest_first(): void
    {
        $this->post_('Older article', ['published_at' => now()->subDays(2)]);
        $this->post_('Newest article', ['published_at' => now()->subHour()]);
        $this->post_('Draft article', ['status' => 'draft']);

        $render = fn (array $settings) => app(\Torgash\Blocks\ZoneRenderer::class)
            ->block(new \Torgash\Blocks\Models\Block(['type' => 'blog_posts', 'settings' => $settings]));

        $html = $render(['heading' => 'From the blog', 'layout' => 'featured', 'limit' => 5]);
        $this->assertStringContainsString('From the blog', $html);
        $this->assertStringNotContainsString('Draft article', $html);
        $this->assertLessThan(strpos($html, 'Older article'), strpos($html, 'Newest article'));
        $this->assertStringContainsString('is-large', $html);

        $empty = Rubric::create(['name' => ['en' => 'Empty'], 'slug' => 'empty-rubric', 'enabled' => true]);
        $this->assertSame('', trim($render(['rubric' => (string) $empty->id])));
    }

    protected function jpeg(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(400, 300);
        $path = tempnam(sys_get_temp_dir(), 'tg').'.jpg';
        imagejpeg($image, $path, 85);

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function post_(string $title, array $attributes = []): Post
    {
        $post = Post::create($attributes + [
            'title' => ['en' => $title],
            'slug' => \Illuminate\Support\Str::slug($title),
            'body' => ['en' => '<p>Text.</p>'],
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $post->rubrics()->attach($this->phones->id);

        return $post;
    }
}

<?php

namespace Tests\Feature\Comments;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use App\Core\Mail\EventMail;
use Torgash\Comments\Models\Comment;
use Torgash\Pages\Models\Page;

/**
 * Comments: one table, a registry of what can be commented on, and a public
 * POST that has to survive being the most reliably abused thing a shop puts
 * online.
 */
class CommentTest extends TestCase
{
    protected Page $page;

    protected function setUp(): void
    {
        parent::setUp();

        // The guard counts per IP across a run; each test starts even.
        RateLimiter::clear('form:comment:ip:127.0.0.1');

        $this->page = Page::create([
            'slug' => 'commented',
            'title' => ['en' => 'A commented page'],
            'body' => ['en' => '<p>Text</p>'],
            'enabled' => true,
        ]);
    }

    /** A guest comment lands in moderation and is not on the page yet. */
    public function test_a_comment_waits_for_moderation_by_default(): void
    {
        Mail::fake();
        // Where the module's own notification goes. With neither this nor a
        // storefront contact address it stays silent — which is a setting, not
        // a failure, so the test states the address it is testing.
        app(SettingsManager::class)->set('torgash.comments', 'notify_email', 'shop@example.com');

        $this->post('http://localhost/comments', $this->form([
            'author_name' => 'Anna',
            'body' => 'Does this ship to Kyiv?',
        ]))->assertRedirect();

        $comment = Comment::firstOrFail();

        $this->assertSame(Comment::PENDING, $comment->status);
        $this->assertSame('page', $comment->target_type);
        $this->assertSame($this->page->id, (int) $comment->target_id);

        // The shop is told by the module itself — a comment nobody knows about
        // is indistinguishable from a form that does not work.
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === 'comments.posted');
    }

    /** With premoderation off it is published at once. */
    public function test_without_premoderation_a_comment_is_published_immediately(): void
    {
        Mail::fake();
        app(SettingsManager::class)->set('torgash.comments', 'premoderate', false);

        $this->post('http://localhost/comments', $this->form([
            'author_name' => 'Anna', 'body' => 'Hello',
        ]))->assertRedirect();

        $this->assertSame(Comment::APPROVED, Comment::firstOrFail()->status);
    }

    /** Approved comments show on the page; pending ones do not. */
    public function test_the_block_shows_approved_comments_only(): void
    {
        $this->blockInZone();

        $this->comment(['author_name' => 'Published one', 'body' => 'Visible', 'status' => Comment::APPROVED]);
        $this->comment(['author_name' => 'Waiting one', 'body' => 'Hidden', 'status' => Comment::PENDING]);

        $response = $this->get('http://localhost/commented')->assertOk();

        $response->assertSee('Published one');
        $response->assertSee('Visible');
        $response->assertDontSee('Waiting one');
        $response->assertDontSee('Hidden');
    }

    /** The honeypot is the cheapest half of the guard, and it must be wired up. */
    public function test_a_filled_honeypot_is_refused(): void
    {
        $this->from('http://localhost/commented')
            ->post('http://localhost/comments', $this->form([
                'author_name' => 'Bot',
                'body' => 'Buy followers',
                FormGuard::TRAP => 'http://spam.example',
            ]))
            ->assertRedirect('http://localhost/commented')
            ->assertSessionHasErrors('comment');

        $this->assertSame(0, Comment::count());
    }

    /** A POST cannot address a table this module has never heard of. */
    public function test_an_unregistered_target_is_refused(): void
    {
        $this->post('http://localhost/comments', $this->form([
            'target_type' => 'invoice',
            'author_name' => 'Anna',
            'body' => 'Hello',
        ]))->assertNotFound();

        $this->assertSame(0, Comment::count());
    }

    /** ...nor an id that does not exist. */
    public function test_a_missing_target_is_refused(): void
    {
        $this->post('http://localhost/comments', $this->form([
            'target_id' => 999999,
            'author_name' => 'Anna',
            'body' => 'Hello',
        ]))->assertNotFound();
    }

    /** A signed-in author is signed with their own name, not one they typed. */
    public function test_a_signed_in_author_cannot_sign_as_somebody_else(): void
    {
        Mail::fake();

        $customer = User::create([
            'name' => 'Real Name', 'email' => 'commenter@example.com', 'password' => bcrypt('secret'),
        ]);

        $this->actingAs($customer)->post('http://localhost/comments', $this->form([
            'author_name' => 'Somebody Else',
            'body' => 'Hello',
        ]))->assertRedirect();

        $comment = Comment::firstOrFail();
        $this->assertSame('Real Name', $comment->author_name);
        $this->assertSame('commenter@example.com', $comment->author_email);
    }

    /** The shop's answer publishes the question with it — half a conversation is worse than none. */
    public function test_replying_publishes_the_comment_it_answers(): void
    {
        $comment = $this->comment(['body' => 'Is it in stock?', 'status' => Comment::PENDING]);
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)
            ->post('http://localhost/admin/comments/'.$comment->id.'/reply', ['body' => 'Yes, it is.'])
            ->assertRedirect();

        $this->assertSame(Comment::APPROVED, $comment->fresh()->status);

        $reply = Comment::where('is_staff', true)->firstOrFail();
        $this->assertSame($comment->id, $reply->parent_id);
        $this->assertSame(Comment::APPROVED, $reply->status);
        $this->assertSame('page', $reply->target_type);
    }

    /** Moderation is two buttons, and the API says the same two words. */
    public function test_the_api_moderates_a_comment(): void
    {
        $comment = $this->comment(['status' => Comment::PENDING]);

        [, $token] = \Torgash\Api\Models\ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests');
        $headers = ['Authorization' => 'Bearer '.$token];

        $read = $this->json('GET', 'http://localhost/api/v2/admin/comments?status=pending', [], $headers)->assertOk();
        $this->assertSame('page', $read->json('data.0.target_type'));
        $this->assertSame('A commented page', $read->json('data.0.target'));

        $this->json('PATCH', 'http://localhost/api/v2/admin/comments/'.$comment->id, ['status' => 'approved'], $headers)->assertOk();

        $this->assertSame(Comment::APPROVED, $comment->fresh()->status);
    }

    /** Deleting a comment takes its replies with it. */
    public function test_deleting_a_comment_removes_its_replies(): void
    {
        $comment = $this->comment(['status' => Comment::APPROVED]);
        $this->comment(['status' => Comment::APPROVED, 'parent_id' => $comment->id, 'is_staff' => true]);

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->delete('http://localhost/admin/comments/'.$comment->id)->assertRedirect();

        $this->assertSame(0, Comment::count());
    }

    /** @return array<string, mixed> */
    protected function form(array $overrides = []): array
    {
        return [
            'target_type' => 'page',
            'target_id' => $this->page->id,
            // The encrypted age stamp the form carries. Minted 30 seconds in
            // the past, because a form submitted the instant it appeared was
            // not typed into — which is exactly what the guard refuses.
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
            ...$overrides,
        ];
    }

    protected function comment(array $attributes = []): Comment
    {
        return Comment::create([
            'target_type' => 'page',
            'target_id' => $this->page->id,
            'author_name' => 'Anna',
            'body' => 'A comment',
            'status' => Comment::APPROVED,
            ...$attributes,
        ]);
    }

    /**
     * Comments are a block; a page shows them because one was placed under it.
     *
     * torgash.blocks subscribes to its zones once, when the module BOOTS —
     * which for a test has already happened by the time the row is inserted.
     * Re-registering the provider rebuilds the subscriptions, which is what a
     * real request does on its own boot.
     */
    protected function blockInZone(): void
    {
        \Torgash\Blocks\Models\Block::create([
            'zone' => 'pages.after_content',
            'type' => 'comments',
            'title' => 'Comments',
            'enabled' => true,
            'sort' => 0,
            'settings' => [],
        ]);

        $this->app->register(new \Torgash\Blocks\BlocksServiceProvider($this->app), true);
    }
}

<?php

namespace Tests\Feature\Comments;

use App\Core\Admin\AdminNotifications;
use App\Models\User;
use Tests\TestCase;
use Torgash\Comments\Models\Comment;
use Torgash\Pages\Models\Page;

/**
 * The moderation queue in the reviews' shape: status tabs with counts, a
 * search, "answered or not", the status in place, and a bulk act over the
 * ticked ones or over everything the filters found — plus the bell.
 */
class CommentsQueueTest extends TestCase
{
    protected User $admin;

    protected Page $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->page = Page::create(['slug' => 'queue-commented', 'title' => ['en' => 'Queue page'], 'body' => ['en' => '<p>x</p>'], 'enabled' => true]);
    }

    protected function comment(array $attributes = []): Comment
    {
        return Comment::create(['target_type' => 'page', 'target_id' => $this->page->id, 'author_name' => 'Anna',
            'body' => 'A comment', 'status' => Comment::PENDING, ...$attributes]);
    }

    public function test_it_opens_on_the_queue_and_searches(): void
    {
        $this->comment(['body' => 'Quokka question waiting']);
        $this->comment(['body' => 'Quokka already out', 'status' => Comment::APPROVED]);

        $this->actingAs($this->admin)->get('http://localhost/admin/comments?q=quokka')->assertOk()
            ->assertSee('Quokka question waiting')->assertDontSee('Quokka already out')
            ->assertSee('Queue page');

        $this->get('http://localhost/admin/comments?q=quokka&status=all')->assertOk()
            ->assertSee('Quokka question waiting')->assertSee('Quokka already out');
    }

    public function test_status_in_place_and_bulk_over_everything_found(): void
    {
        $one = $this->comment(['body' => 'Bulk zebra one']);
        $two = $this->comment(['body' => 'Bulk zebra two']);
        $other = $this->comment(['body' => 'Somebody else']);

        $this->actingAs($this->admin)->patch("http://localhost/admin/comments/{$other->id}/status", ['status' => Comment::REJECTED], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('comment-status-'.$other->id, false);
        $this->assertSame(Comment::REJECTED, $other->fresh()->status);

        // "Every comment found" is the search, read again — not the page.
        $this->post('http://localhost/admin/comments/bulk', ['action' => Comment::APPROVED, 'scope' => 'found', 'status' => Comment::PENDING, 'q' => 'zebra'])
            ->assertRedirect();
        $this->assertSame(Comment::APPROVED, $one->fresh()->status);
        $this->assertSame(Comment::APPROVED, $two->fresh()->status);
        $this->assertSame(Comment::REJECTED, $other->fresh()->status, 'outside the search, untouched');
    }

    public function test_the_bell_counts_what_is_waiting(): void
    {
        $this->comment();
        $this->actingAs($this->admin);

        $rows = collect(app(AdminNotifications::class)->all());
        $this->assertGreaterThanOrEqual(1, (int) ($rows->firstWhere('key', 'comments')['count'] ?? 0));
    }
}

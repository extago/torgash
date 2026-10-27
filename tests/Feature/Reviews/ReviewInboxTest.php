<?php

namespace Tests\Feature\Reviews;

use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Models\Review;

/**
 * The moderation screen at a thousand reviews: filters that narrow the queue,
 * a bulk act over EVERY review found (read by the same query as the list), and
 * the status moved in place through Moderate, so the average follows.
 */
class ReviewInboxTest extends TestCase
{
    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
        $this->product = Product::create(['sku' => 'RVW-'.random_int(1000, 9999), 'name' => ['en' => 'Kettle Zeta'], 'slug' => 'kettle-zeta-'.random_int(1000, 9999), 'price' => 10_00, 'stock' => 5, 'status' => 'active']);
    }

    protected function review(array $attributes = []): Review
    {
        return Review::create($attributes + [
            'product_id' => $this->product->id, 'user_id' => null, 'author_name' => 'Olena',
            'body' => 'Fine kettle', 'rating' => 5, 'status' => Review::PENDING, 'verified' => false,
        ]);
    }

    public function test_filters_narrow_the_queue(): void
    {
        $this->review(['author_name' => 'Low-one', 'rating' => 1, 'body' => 'Leaks water']);
        $this->review(['author_name' => 'High-one', 'rating' => 5, 'verified' => true]);

        $this->actingAs($this->admin)->get('http://localhost/admin/reviews?rating=low')
            ->assertOk()->assertSee('Low-one')->assertDontSee('High-one');
        $this->get('http://localhost/admin/reviews?verified=1')
            ->assertSee('High-one')->assertDontSee('Low-one');
        $this->get('http://localhost/admin/reviews?q=leaks')
            ->assertSee('Low-one')->assertDontSee('High-one');
        $this->get('http://localhost/admin/reviews?q=zeta')
            ->assertSee('Low-one')->assertSee('High-one');
        $this->get('http://localhost/admin/reviews?product='.$this->product->id.'&sort=low')->assertOk();
    }

    public function test_a_bulk_act_over_every_review_found_touches_only_those(): void
    {
        $five = [$this->review(['rating' => 5]), $this->review(['rating' => 5])];
        $one = $this->review(['rating' => 1]);

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/reviews/bulk', ['action' => Review::PUBLISHED, 'scope' => 'found', 'status' => Review::PENDING, 'rating' => '5'])
            ->assertRedirect();

        foreach ($five as $review) {
            $this->assertSame(Review::PUBLISHED, $review->fresh()->status);
        }
        $this->assertSame(Review::PENDING, $one->fresh()->status);
    }

    public function test_the_status_moves_in_place_and_the_average_follows(): void
    {
        $review = $this->review(['rating' => 4]);

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/reviews/{$review->id}/status", ['status' => Review::PUBLISHED], ['HX-Request' => 'true'])
            ->assertOk()
            ->assertSee('review-status-'.$review->id, false);

        $this->assertSame(Review::PUBLISHED, $review->fresh()->status);
        $this->assertEquals(4.0, (float) \Illuminate\Support\Facades\DB::table('m_reviews_aggregates')->where('product_id', $this->product->id)->value('rating'));
    }

    /** Criteria: a list and a form of their own, the switch in place. */
    public function test_criteria_have_a_list_a_form_and_a_switch(): void
    {
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/reviews/criteria', ['name' => 'Fit', 'sort' => 5, 'enabled' => 1])
            ->assertRedirect();

        $criterion = \Torgash\Reviews\Models\Criterion::where('code', 'fit')->firstOrFail();

        $this->get('http://localhost/admin/reviews/criteria')->assertOk()->assertSee('Fit');
        $this->get("http://localhost/admin/reviews/criteria/{$criterion->id}/edit")->assertOk()->assertSee(route('admin.reviews.criteria.update', $criterion), false);

        $this->patch("http://localhost/admin/reviews/criteria/{$criterion->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('criterion-status-'.$criterion->id, false);
        $this->assertFalse((bool) $criterion->fresh()->enabled);
    }
}

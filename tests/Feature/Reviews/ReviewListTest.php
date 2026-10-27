<?php

namespace Tests\Feature\Reviews;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Reviews\Listing;
use Torgash\Reviews\Models\Review;
use Torgash\Reviews\Votes;

/**
 * The reviews list a reader narrows and pages through — filters by stars,
 * period, buyers and photos, "show more", and the most helpful praise beside
 * the most helpful complaint, each drawn only where the shop's thresholds say.
 */
class ReviewListTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'RL-1', 'name' => ['en' => 'Lamp'], 'slug' => 'rl-lamp',
            'price' => 50_00, 'stock' => 10, 'status' => 'active',
        ]);
    }

    /** Stars bucket as the spread rounds (4.5 is five); the period, buyers only and photos narrow too. */
    public function test_filters_narrow_the_list(): void
    {
        $five = $this->review('Five', 4.5, verified: true);
        $two = $this->review('Two', 2);
        $old = $this->review('Old', 5);
        $old->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $listing = app(Listing::class);
        $bodies = fn (array $query) => $listing->forProduct($this->product->id, Listing::DEFAULT, Listing::state($query))->pluck('body')->all();

        $this->assertEqualsCanonicalizing(['Five', 'Old'], $bodies(['review_stars' => 5]));
        $this->assertSame(['Two'], $bodies(['review_stars' => 2]));
        $this->assertEqualsCanonicalizing(['Five', 'Two'], $bodies(['review_period' => 30]));
        $this->assertSame(['Five'], $bodies(['review_buyers' => 1]));
        $this->assertSame([], $bodies(['review_photos' => 1]));
        $this->assertSame(3, $listing->count($this->product->id), 'nonsense in the address is the default');
        $this->assertEqualsCanonicalizing(['Five', 'Two', 'Old'], $bodies(['review_stars' => 9, 'review_period' => 7]));
    }

    /** A page at a time; "show more" is a link to a bigger page, and a filter that leaves nothing is not "no reviews yet". */
    public function test_show_more_and_an_empty_filter(): void
    {
        app(SettingsManager::class)->set('torgash.reviews', 'per_page', 2);
        foreach (range(1, 5) as $i) {
            $this->review("Review {$i}", 5);
        }

        $html = $this->get('http://localhost/product/rl-lamp')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, '<article class="py-6'));
        $this->assertStringContainsString('review_shown=4', $html);

        $more = $this->get('http://localhost/product/rl-lamp/reviews?review_shown=4')->assertOk()->getContent();
        $this->assertSame(4, substr_count($more, '<article class="py-6'));

        $none = $this->get('http://localhost/product/rl-lamp?review_stars=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('Nobody has reviewed this product yet.', $none);
        $this->assertStringContainsString('No reviews match', $none);
    }

    /** Filters appear from `filters_from` reviews on; the pair of highlights only past `highlights_from` and with a vote behind each. */
    public function test_thresholds_decide_what_is_drawn(): void
    {
        app(SettingsManager::class)->set('torgash.reviews', 'filters_from', 3);
        app(SettingsManager::class)->set('torgash.reviews', 'highlights_from', 3);

        $praise = $this->review('Lovely light', 5);
        $this->review('Fine', 4);
        $this->assertStringNotContainsString('Buyers only', $this->get('http://localhost/product/rl-lamp')->getContent());

        $complaint = $this->review('Flickers', 1);
        $html = $this->get('http://localhost/product/rl-lamp')->getContent();
        $this->assertStringContainsString('Buyers only', $html);
        $this->assertStringNotContainsString('The most helpful critical review', $html, 'nobody voted yet');

        app(Votes::class)->cast($praise, User::factory()->create(), true);
        app(Votes::class)->cast($complaint, User::factory()->create(), true);

        $html = $this->get('http://localhost/product/rl-lamp')->getContent();
        $this->assertStringContainsString('The most helpful positive review', $html);
        $this->assertStringContainsString('The most helpful critical review', $html);
    }

    protected function review(string $body, float $rating, bool $verified = false): Review
    {
        return Review::create([
            'product_id' => $this->product->id,
            'user_id' => User::factory()->create()->id,
            'author_name' => 'Reader',
            'body' => $body,
            'rating' => $rating,
            'verified' => $verified,
            'status' => Review::PUBLISHED,
        ]);
    }
}

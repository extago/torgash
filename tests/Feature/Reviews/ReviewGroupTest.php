<?php

namespace Tests\Feature\Reviews;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Guard\Invariants\Invariants;
use Torgash\Guard\Models\Finding;
use Torgash\Reviews\Aggregates;
use Torgash\Reviews\Eligibility;
use Torgash\Reviews\Listing;
use Torgash\Reviews\Models\Review;

/**
 * Variations share their reviews (`share_variations`, on by default): a
 * group is one model to a reader — the list, the stored mark and "already
 * reviewed" read every member — while each review stays on the variation it
 * was written about, so switching the setting off splits them back.
 */
class ReviewGroupTest extends TestCase
{
    use MakesVariationAxes;

    protected Product $black;

    protected Product $white;

    protected function setUp(): void
    {
        parent::setUp();

        [, $colours] = $this->variationAxis('Colour', ['Black', 'White']);
        $this->black = Product::create([
            'sku' => 'RG-B', 'name' => ['en' => 'Phone'], 'slug' => 'rg-black',
            'price' => 100_00, 'stock' => 5, 'status' => 'active', 'variation_group' => 'rg-phone',
        ]);
        $this->white = Product::create([
            'sku' => 'RG-W', 'name' => ['en' => 'Phone'], 'slug' => 'rg-white',
            'price' => 100_00, 'stock' => 5, 'status' => 'active', 'variation_group' => 'rg-phone',
        ]);
        $this->answer($this->black, $colours['Black']);
        $this->answer($this->white, $colours['White']);
    }

    public function test_a_group_reads_every_members_reviews(): void
    {
        $this->review($this->black, 'Great in black', 5);
        $this->review($this->white, 'Scratches easily', 3);

        $listing = app(Listing::class);
        $this->assertEqualsCanonicalizing(['Great in black', 'Scratches easily'], $listing->forProduct($this->white->id)->pluck('body')->all());

        $aggregates = app(Aggregates::class);
        foreach ([$this->black, $this->white] as $member) {
            $row = $aggregates->for($member->id);
            $this->assertSame(2, (int) $row->reviews_count);
            $this->assertEquals(4.0, (float) $row->rating);
        }

        // The page says which variation a neighbour's review was about.
        $html = $this->get('http://localhost/product/rg-white')->assertOk()->getContent();
        $this->assertStringContainsString('Great in black', $html);
        $this->assertStringContainsString('About the variant: Black', $html);
        $this->assertStringNotContainsString('About the variant: White', $html);

        // The guard's statement reads a group's row as the group's, not as drift.
        app(Invariants::class)->run('reviews.aggregate_matches_reviews');
        $this->assertSame(0, Finding::where('key', 'reviews.aggregate_matches_reviews')->count());
    }

    public function test_one_review_per_person_per_model(): void
    {
        $user = User::factory()->create();
        $this->review($this->black, 'Mine', 5, $user);

        $this->assertNotNull(app(Eligibility::class)->refusal($user, $this->white));
    }

    public function test_switching_it_off_splits_them_back_and_regrouping_recounts(): void
    {
        $this->review($this->black, 'Great in black', 5);
        $this->review($this->white, 'Scratches easily', 3);

        app(SettingsManager::class)->set('torgash.reviews', 'share_variations', false);
        $aggregates = app(Aggregates::class);
        $this->assertSame(1, (int) $aggregates->for($this->white->id)->reviews_count, 'switched off');
        $this->assertEquals(3.0, (float) $aggregates->for($this->white->id)->rating);
        $this->assertSame(['Scratches easily'], app(Listing::class)->forProduct($this->white->id)->pluck('body')->all());

        app(SettingsManager::class)->set('torgash.reviews', 'share_variations', true);
        $this->assertSame(2, (int) $aggregates->for($this->white->id)->reviews_count);

        // Taken out of the group: each half counts its own again.
        $this->white->update(['variation_group' => null]);
        $this->assertSame(1, (int) $aggregates->for($this->white->id)->reviews_count, 'regrouped white');
        $this->assertSame(1, (int) $aggregates->for($this->black->id)->reviews_count);
        $this->assertEquals(5.0, (float) $aggregates->for($this->black->id)->rating);
    }

    protected function review(Product $product, string $body, float $rating, ?User $user = null): Review
    {
        $review = Review::create([
            'product_id' => $product->id,
            'user_id' => ($user ?? User::factory()->create())->id,
            'author_name' => 'Reader',
            'body' => $body,
            'rating' => $rating,
            'status' => Review::PUBLISHED,
        ]);
        app(Aggregates::class)->recalculate($product->id);

        return $review;
    }
}

<?php

namespace Tests\Feature\Reviews;

use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\ImportRunner;
use Torgash\Reviews\Aggregates;
use Torgash\Reviews\Listing;
use Torgash\Reviews\Models\Review;
use Torgash\Reviews\Models\ReviewVote;
use Torgash\Reviews\Photos;
use Torgash\Reviews\Votes;

/**
 * The second wave of reviews (PLAN §32.3): photographs, "was this helpful",
 * an order to read them in, and the API and file they travel by.
 *
 * The statements worth pinning are the ones a shop is damaged by when they
 * stop being true — a pending review showing its pictures, a ranking a script
 * can buy, an import that doubles the reviews on its second run, and a file
 * that could assert the one badge a reader actually believes.
 */
class ReviewsSecondWaveTest extends TestCase
{
    protected Product $product;

    protected User $buyer;

    protected ?string $token = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->product = Product::create([
            'sku' => 'KETTLE', 'name' => ['en' => 'Kettle'], 'slug' => 'kettle',
            'price' => 500_00, 'stock' => 10, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);

        $this->buyer = User::factory()->create();
    }

    /** A photograph rides the review's own POST; there is no upload endpoint to guard. */
    public function test_a_photo_is_stored_with_the_review_and_shown_with_it(): void
    {
        $this->setting('photos_max', 3);
        $this->setting('premoderate', false);

        $this->actingAs($this->buyer)->post('http://localhost/product/kettle/review', [
            'rating' => 5,
            'body' => 'Boils fast.',
            'photos' => [UploadedFile::fake()->image('kettle.jpg')],
        ] + $this->guardFields());

        $review = Review::reviews()->firstOrFail();

        $this->assertCount(1, Photos::of($review));

        $body = $this->get('http://localhost/product/kettle')->assertOk()->getContent();
        $this->assertStringContainsString('data-review-photo', $body);
    }

    /**
     * …and a pending one shows nobody its pictures, without a line of code
     * saying so: a photo is only ever drawn with the review it belongs to.
     */
    public function test_a_pending_review_shows_neither_its_words_nor_its_photos(): void
    {
        $this->setting('photos_max', 3);
        $this->setting('premoderate', true);

        $this->actingAs($this->buyer)->post('http://localhost/product/kettle/review', [
            'rating' => 2, 'body' => 'Leaks everywhere.',
            'photos' => [UploadedFile::fake()->image('leak.jpg')],
        ] + $this->guardFields());

        $review = Review::reviews()->firstOrFail();
        $this->assertSame(Review::PENDING, $review->status);
        $this->assertCount(1, Photos::of($review), 'the photo is stored, waiting with it');

        $body = $this->get('http://localhost/product/kettle')->assertOk()->getContent();

        $this->assertStringNotContainsString('Leaks everywhere', $body);
        $this->assertStringNotContainsString('data-review-photo', $body);
    }

    /** A refused photo never costs the review — the words are the valuable half. */
    public function test_a_refused_photo_does_not_lose_the_review(): void
    {
        $this->setting('photos_max', 1);
        $this->setting('premoderate', false);

        $this->actingAs($this->buyer)->post('http://localhost/product/kettle/review', [
            'rating' => 4, 'body' => 'Four paragraphs worth keeping.',
            'photos' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
        ] + $this->guardFields());

        $review = Review::reviews()->firstOrFail();

        $this->assertSame('Four paragraphs worth keeping.', $review->body);
        $this->assertCount(1, Photos::of($review), 'the second was refused, the first taken');
    }

    /** One person, one answer, changeable — and pressing it again takes it back. */
    public function test_a_vote_is_one_per_person_and_can_be_changed_or_withdrawn(): void
    {
        $review = $this->publishedReview();
        $reader = User::factory()->create();
        $votes = app(Votes::class);

        $this->assertTrue($votes->cast($review, $reader, true));
        $this->assertSame(1, $review->fresh()->helpful_yes);

        // Changed: still one row, now on the other side.
        $this->assertFalse($votes->cast($review, $reader, false));
        $this->assertSame(0, $review->fresh()->helpful_yes);
        $this->assertSame(1, $review->fresh()->helpful_no);
        $this->assertSame(1, ReviewVote::count());

        // Pressed again: taken back. The button is the state, not a counter.
        $this->assertNull($votes->cast($review, $reader, false));
        $this->assertSame(0, $review->fresh()->helpful_no);
        $this->assertSame(0, ReviewVote::count());
    }

    /** Never your own: the author is the one person the number cannot be evidence from. */
    public function test_nobody_votes_on_their_own_review(): void
    {
        $review = $this->publishedReview(author: $this->buyer);

        app(Votes::class)->cast($review, $this->buyer, true);

        $this->assertSame(0, $review->fresh()->helpful_yes);
        $this->assertSame(0, ReviewVote::count());
    }

    /** The button's own request: a signed-in reader's vote answers the pair of buttons, lit. */
    public function test_the_button_casts_the_vote_through_the_route(): void
    {
        $review = $this->publishedReview();
        $reader = \App\Models\User::factory()->create();

        $this->actingAs($reader)->post(route('reviews.helpful', $review), ['helpful' => 'true'])
            ->assertOk()
            ->assertSee('aria-pressed="true"', false);

        $this->assertSame(1, $review->fresh()->helpful_yes);
    }

    /** A guest cannot vote at all — a ranking a script can buy is worse than none. */
    public function test_a_guest_cannot_vote(): void
    {
        $review = $this->publishedReview();

        $this->post(route('reviews.helpful', $review), ['helpful' => 1])->assertRedirect();

        $this->assertSame(0, ReviewVote::count());
    }

    /**
     * "Most helpful" ranks by the YES votes alone. Subtracting the noes would
     * let a brigade bury a review by voting it down.
     */
    public function test_most_helpful_ranks_by_the_yes_votes_alone(): void
    {
        $quiet = $this->publishedReview('Quiet but useful', rating: 5);
        $loud = $this->publishedReview('Loud and argued over', rating: 3);

        $this->votes($quiet, yes: 3, no: 0);
        $this->votes($loud, yes: 9, no: 20);

        $order = app(Listing::class)->forProduct((int) $this->product->id, 'helpful')->pluck('id')->all();
        $this->assertSame([$loud->id, $quiet->id], $order);

        // …and the other orders are what they say.
        $best = app(Listing::class)->forProduct((int) $this->product->id, 'best')->first();
        $this->assertSame($quiet->id, $best->id);

        // A word nobody knows is the default, never an error: this is a link
        // somebody may have kept.
        $this->assertSame('newest', Listing::normalise('nonsense'));
    }

    /** The order control is links that work without JavaScript. */
    public function test_the_product_page_reads_the_order_from_the_address(): void
    {
        $this->publishedReview('First one', rating: 2);
        $this->publishedReview('Second one', rating: 5);

        $body = $this->get('http://localhost/product/kettle?reviews=best')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'First one'),
            strpos($body, 'Second one'),
            'the five-star review is printed above the two-star one',
        );

        // The same order as the HTMX fragment the links fetch — this address
        // answered 500 once (a class used without its import).
        $fragment = $this->get('http://localhost/product/kettle/reviews?reviews=best')->assertOk()->getContent();
        $this->assertLessThan(strpos($fragment, 'First one'), strpos($fragment, 'Second one'));
    }

    /** Moderating over the API moves the product's average, because it is the same code. */
    public function test_the_api_publishes_a_review_through_the_shops_own_moderation(): void
    {
        $this->setting('premoderate', true);
        $review = $this->publishedReview('Waiting', rating: 4);
        $review->update(['status' => Review::PENDING]);
        app(Aggregates::class)->recalculate((int) $this->product->id);

        $this->assertSame(0, (int) (app(Aggregates::class)->for((int) $this->product->id)?->reviews_count ?? 0));

        $this->apiPatch('/api/v2/admin/reviews/'.$review->id, ['status' => 'published'])->assertOk();

        $this->assertSame(Review::PUBLISHED, $review->fresh()->status);
        $this->assertSame(1, (int) (app(Aggregates::class)->for((int) $this->product->id)?->reviews_count ?? 0));
    }

    /** Answering is an ACTION, because a reply is not a column. */
    public function test_the_api_answers_a_review_as_the_shop(): void
    {
        $review = $this->publishedReview();

        $this->apiPost('/api/v2/admin/reviews/'.$review->id.'/actions/reply', [
            'body' => 'Thank you — we changed the packaging.',
        ])->assertOk();

        $reply = Review::where('parent_id', $review->id)->firstOrFail();

        $this->assertTrue((bool) $reply->is_staff);
        $this->assertSame(Review::PUBLISHED, $reply->status);
    }

    /** What the listing holds, and how a client asks for the reviews alone. */
    public function test_the_api_lists_both_and_can_be_narrowed_to_reviews(): void
    {
        $review = $this->publishedReview();
        Review::create([
            'product_id' => $this->product->id, 'parent_id' => $review->id,
            'user_id' => $this->buyer->id, 'author_name' => 'Shop', 'body' => 'An answer',
            'status' => Review::PUBLISHED, 'is_staff' => true,
        ]);

        // Both by default: a customer's answer waits in the same moderation
        // queue a review does, and a listing that dropped it would leave it
        // unpublished for ever.
        $this->assertCount(2, $this->apiGet('/api/v2/admin/reviews')->assertOk()->json('data'));

        $ids = collect($this->apiGet('/api/v2/admin/reviews?only=reviews')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$review->id], $ids->all());

        // …and the review carries its own answers inline whichever way it is
        // asked for.
        $this->assertCount(1, $this->apiGet('/api/v2/admin/reviews?only=reviews')->json('data.0.replies'));
    }

    /**
     * An import is matched on the external id, so running it twice does not
     * double the shop's reviews — and it cannot assert the verified badge.
     */
    public function test_an_import_is_repeatable_and_cannot_claim_a_purchase(): void
    {
        $csv = "external_id,product_sku,author_name,rating,body,status,verified\n"
            ."old-42,KETTLE,Olena,5,Brought over from the old shop,published,yes\n";

        $this->import($csv);
        $this->import($csv);

        $reviews = Review::reviews()->get();

        $this->assertCount(1, $reviews, 'the second run updated the row it had already made');
        $this->assertSame('Olena', $reviews[0]->author_name);
        $this->assertFalse((bool) $reviews[0]->verified, 'a file may not mark its own homework');

        // The average was recomputed once the file had landed.
        $this->assertSame(1, (int) (app(Aggregates::class)->for((int) $this->product->id)?->reviews_count ?? 0));
    }

    /** @param int $yes @param int $no */
    protected function votes(Review $review, int $yes, int $no): void
    {
        foreach (range(1, $yes + $no) as $i) {
            app(Votes::class)->cast($review, User::factory()->create(), $i <= $yes);
        }
    }

    /**
     * A published review by a DIFFERENT person each time: one review per
     * person per product is a partial unique index, and a test that ignored it
     * would be testing a state the shop cannot reach.
     */
    protected function publishedReview(string $body = 'A review', float $rating = 5, ?User $author = null): Review
    {
        return Review::create([
            'product_id' => $this->product->id,
            'user_id' => ($author ?? User::factory()->create())->id,
            'author_name' => 'Buyer',
            'body' => $body,
            'rating' => $rating,
            'status' => Review::PUBLISHED,
        ]);
    }

    protected function import(string $csv): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rev').'.csv';
        file_put_contents($path, $csv);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $runner = app(ImportRunner::class);
        $runner->commit('review', $runner->rowsOf($path, 'review'));
    }

    protected function setting(string $key, mixed $value): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.reviews', $key, $value);
    }

    /** The guard's own fields, so what is under test is a real form's POST. */
    protected function guardFields(): array
    {
        return [
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ];
    }

    /** One admin token for the whole test, issued the way the API issues them. */
    protected function token(): string
    {
        return $this->token ??= ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests')[1];
    }

    protected function api(string $method, string $url, array $data = [])
    {
        return $this->json($method, 'http://localhost'.$url, $data, [
            'Authorization' => 'Bearer '.$this->token(),
        ]);
    }

    protected function apiGet(string $url)
    {
        return $this->api('GET', $url);
    }

    protected function apiPost(string $url, array $data)
    {
        return $this->api('POST', $url, $data);
    }

    protected function apiPatch(string $url, array $data)
    {
        return $this->api('PATCH', $url, $data);
    }
}

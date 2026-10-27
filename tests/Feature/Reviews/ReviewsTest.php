<?php

namespace Tests\Feature\Reviews;

use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Sorting\Sorts;
use Torgash\Checkout\Models\Order;
use Torgash\Reviews\Aggregates;
use App\Core\Mail\EventMail;
use Torgash\Reviews\Criteria;
use Torgash\Reviews\Models\Criterion;
use Torgash\Reviews\Models\Review;

/**
 * Reviews with a rating (decided with the owner, 2026-09-16).
 *
 * The three rules that shape the whole module are the ones pinned first: a
 * guest never writes one, buying is a MARK rather than a gate unless the shop
 * says otherwise, and what a shop asks people to rate is its own list —
 * attached to nothing it is asked everywhere, attached to a branch it is asked
 * under it, because a category here always means its subcategories.
 */
class ReviewsTest extends TestCase
{
    protected Category $laptops;

    protected Category $gaming;

    protected function setUp(): void
    {
        parent::setUp();

        $this->laptops = Category::create(['name' => ['en' => 'Laptops'], 'slug' => 'rv-laptops', 'enabled' => true]);
        $this->gaming = Category::create([
            'name' => ['en' => 'Gaming laptops'], 'slug' => 'rv-gaming', 'enabled' => true,
            'parent_id' => $this->laptops->id,
        ]);

        app(Criteria::class)->flush();
        app(Aggregates::class)->flush();
    }

    /** A guest is told where to sign in, and the endpoint is not a way round it. */
    public function test_a_guest_cannot_review(): void
    {
        $product = $this->product('RV-1');

        $this->get('http://localhost/product/rv-1')->assertOk()
            ->assertSee(__('Please sign in to leave a review'))
            ->assertDontSee(__('Write a review'));

        $this->write('rv-1', ['rating' => 5])
            ->assertRedirect(); // to the login screen, not into the table

        $this->assertSame(0, Review::count());
    }

    /**
     * The ordinary case: scores per criterion, an overall mark computed from
     * them, and nothing on the page until somebody has read it.
     */
    public function test_a_customer_writes_one_and_it_waits_for_moderation(): void
    {
        $product = $this->product('RV-2', [$this->gaming->id]);
        $sound = $this->criterion('sound', 'Sound');
        $screen = $this->criterion('screen', 'Screen');

        $this->actingAs($this->customer());

        $this->write('rv-2', [
            'criteria' => [$sound->id => 4, $screen->id => 5],
            'pros' => 'Quiet',
            'cons' => 'Heavy',
            'body' => 'Happy with it.',
            'recommend' => 'yes',
        ])->assertRedirect();

        $review = Review::firstOrFail();

        $this->assertSame(Review::PENDING, $review->status);
        $this->assertSame(4.5, (float) $review->rating);
        $this->assertSame(2, $review->ratings()->count());

        // Nothing waiting for a check may move the number on the card.
        $this->assertNull(app(Aggregates::class)->for((int) $product->id));

        $this->get('http://localhost/product/rv-2')->assertOk()->assertDontSee('Happy with it.');
    }

    /** Publishing is what puts it on the page and into the average. */
    public function test_publishing_moves_the_average_and_the_page(): void
    {
        $product = $this->product('RV-3');

        $this->published($product, 5);
        $this->published($product, 4);

        $row = app(Aggregates::class)->for((int) $product->id);

        $this->assertSame(2, (int) $row->reviews_count);
        $this->assertSame(4.5, (float) $row->rating);
        $this->assertSame(1, app(Aggregates::class)->spread((int) $product->id)[5]);

        $this->get('http://localhost/product/rv-3')->assertOk()
            ->assertSee('4.5')
            ->assertSee('aggregateRating', false);
    }

    /** ...and rejecting a published one takes it back out, which is the same act in reverse. */
    public function test_rejecting_takes_it_out_of_the_average(): void
    {
        $product = $this->product('RV-4');
        $good = $this->published($product, 5);
        $this->published($product, 1);

        $this->actingAs($this->admin());
        $this->patch('http://localhost/admin/reviews/'.$good->id.'/status', ['status' => Review::REJECTED])
            ->assertRedirect();

        $row = app(Aggregates::class)->for((int) $product->id);

        $this->assertSame(1, (int) $row->reviews_count);
        $this->assertSame(1.0, (float) $row->rating);
    }

    /**
     * The mark that does the work. It is worked out when the review is written
     * and frozen: an order cancelled next year does not retract what was true
     * then.
     */
    public function test_a_buyer_is_marked_as_a_verified_purchase(): void
    {
        $product = $this->product('RV-5');
        $user = $this->customer();

        Order::create([
            'customer_name' => 'Buyer', 'email' => $user->email, 'user_id' => $user->id,
            'subtotal' => 100_00, 'total' => 100_00, 'status' => 'new',
            'items' => [['product_id' => $product->id, 'qty' => 1, 'price' => 100_00]],
        ]);

        $this->actingAs($user);
        $this->write('rv-5', ['rating' => 5])->assertRedirect();

        $this->assertTrue(Review::firstOrFail()->verified);
    }

    /** ...and somebody who did not buy still gets a voice, unless the shop says otherwise. */
    public function test_a_non_buyer_may_review_but_is_not_marked(): void
    {
        $this->product('RV-6');

        $this->actingAs($this->customer());
        $this->write('rv-6', ['rating' => 4])->assertRedirect();

        $this->assertFalse(Review::firstOrFail()->verified);
    }

    public function test_the_strict_shop_refuses_a_non_buyer(): void
    {
        $this->product('RV-7');
        $this->settings(['buyers_only' => true]);

        $this->actingAs($this->customer());
        $this->write('rv-7', ['rating' => 4])
            ->assertRedirect()
            ->assertSessionHasErrors('review');

        $this->assertSame(0, Review::count());
    }

    /** A second opinion is an edit of the first, not a second row. */
    public function test_one_review_per_person_per_product(): void
    {
        $this->product('RV-8');
        $user = $this->customer();

        $this->actingAs($user);
        $this->write('rv-8', ['rating' => 5])->assertRedirect();
        $this->write('rv-8', ['rating' => 1])
            ->assertSessionHasErrors('review');

        $this->assertSame(1, Review::count());
    }

    /**
     * Which criteria are asked: attached to nothing means everywhere, and
     * attached to a branch covers everything under it — the rule the whole
     * engine already follows, which is why there is no "include subcategories"
     * switch to get wrong.
     */
    public function test_criteria_are_asked_by_category_and_inherit_down_the_tree(): void
    {
        $everywhere = $this->criterion('value', 'Value for money');
        $laptopOnly = $this->criterion('battery', 'Battery life', [$this->laptops->id]);
        $elsewhere = $this->criterion('fit', 'Fit', [$this->otherCategory()->id]);

        $product = $this->product('RV-9', [$this->gaming->id]);
        $asked = app(Criteria::class)->forProduct($product)->pluck('code')->all();

        $this->assertSame(['value', 'battery'], $asked);

        // A score posted for a criterion this product is not rated by is
        // dropped rather than stored: anything that sends one is a stale page
        // or a hand-made request, and either way it would put "Fit" on a
        // laptop.
        $this->actingAs($this->customer());
        $this->write('rv-9', [
            'criteria' => [$everywhere->id => 5, $laptopOnly->id => 3, $elsewhere->id => 1],
        ])->assertRedirect();

        $review = Review::firstOrFail();

        $this->assertSame(2, $review->ratings()->count());
        $this->assertSame(4.0, (float) $review->rating);
    }

    /** A review with nothing in it at all is not a review. */
    public function test_a_review_with_no_rating_is_refused(): void
    {
        $this->product('RV-10');

        $this->actingAs($this->customer());
        $this->write('rv-10', ['body' => 'Nothing to say really'])
            ->assertSessionHasErrors();

        $this->assertSame(0, Review::count());
    }

    /**
     * "Best rated first" keeps the products nobody has reviewed — a young shop
     * would otherwise lose half its catalogue the moment somebody chose that
     * order.
     */
    public function test_the_rating_sort_keeps_products_with_no_reviews(): void
    {
        $good = $this->product('RV-11', [$this->laptops->id]);
        $poor = $this->product('RV-12', [$this->laptops->id]);
        $this->product('RV-13', [$this->laptops->id]);

        $this->published($good, 5);
        $this->published($poor, 2);

        // Registered is not offered: a module may not silently add a control
        // to somebody's storefront, so the shop ticks it — Flags' own rule,
        // which the sort registry inherited.
        app(SettingsManager::class)->set('torgash.catalog', 'catalog_sorts', ['newest', 'rating']);
        app(Sorts::class)->flush();

        $this->get('http://localhost/catalog/rv-laptops?sort=rating')->assertOk()
            ->assertSeeInOrder(['RV-11 thing', 'RV-12 thing', 'RV-13 thing']);
    }

    /** The shop's answer is published under the review it answers, and publishes it. */
    public function test_a_reply_publishes_the_review_it_answers(): void
    {
        $product = $this->product('RV-14');

        $this->actingAs($this->customer());
        $this->write('rv-14', ['rating' => 2, 'body' => 'It broke'])->assertRedirect();

        $review = Review::reviews()->firstOrFail();

        $this->actingAs($this->admin());
        $this->post('http://localhost/admin/reviews/'.$review->id.'/reply', ['body' => 'We will replace it.'])
            ->assertRedirect();

        $this->assertSame(Review::PUBLISHED, $review->fresh()->status);
        $this->assertSame(1, $review->replies()->count());
        $this->assertSame(1, (int) app(Aggregates::class)->for((int) $product->id)->reviews_count);

        $this->get('http://localhost/product/rv-14')->assertOk()
            ->assertSeeInOrder(['It broke', 'We will replace it.']);
    }


    /**
     * The button opens the dialog that is actually on the page.
     *
     * It did not: <x-modal> prefixes its `name` with "modal-", the section
     * passed a name that already carried the prefix, and the trigger called
     * getElementById on an id nothing had — so "Write a review" threw in the
     * console and did nothing at all. Pinned as an id match rather than as
     * markup, because that is the only part that has to agree.
     */
    public function test_the_write_button_opens_the_dialog_that_exists(): void
    {
        $product = $this->product('RV-15');

        $this->actingAs($this->customer());

        $html = $this->get('http://localhost/product/rv-15')->assertOk()->getContent();

        preg_match('/data-open-dialog="(modal-review-\d+)"/', $html, $trigger);
        $this->assertNotEmpty($trigger, 'no trigger for the review dialog');
        $this->assertStringContainsString('id="'.$trigger[1].'"', $html);
    }

    /**
     * A review is a conversation. Anybody signed in may answer one — its own
     * author included, which is the case the owner asked for: the shop's
     * public answer is usually addressed to them.
     */
    public function test_a_customer_can_answer_a_review_including_their_own(): void
    {
        $product = $this->product('RV-16');
        $review = $this->published($product, 4);
        $author = User::findOrFail($review->user_id);

        $this->settings(['premoderate' => false]);

        $this->actingAs($this->someone('passer-by'));
        $this->answer($review, 'Mine broke too.')->assertRedirect();

        $this->actingAs($author);
        $this->answer($review, 'Update: the shop replaced it.')->assertRedirect();

        $this->assertSame(2, $review->fresh()->replies()->count());

        // A reply is not a review: it carries no rating and moves no average.
        $this->assertSame(1, (int) app(Aggregates::class)->for((int) $product->id)->reviews_count);
        $this->assertSame(4.0, (float) app(Aggregates::class)->for((int) $product->id)->rating);

        $this->get('http://localhost/product/rv-16')->assertOk()
            ->assertSee('Mine broke too.')
            ->assertSee('Update: the shop replaced it.');
    }

    /** Whoever wrote the review is told that somebody answered it. */
    public function test_the_author_is_emailed_when_somebody_answers(): void
    {
        Mail::fake();

        $product = $this->product('RV-17');
        $review = $this->published($product, 5);

        $this->settings(['premoderate' => false]);

        $this->actingAs($this->someone('passer-by'));
        $this->answer($review, 'Is it still working?')->assertRedirect();

        Mail::assertQueued(EventMail::class, function (EventMail $mail) use ($review) {
            return $mail->event === 'reviews.replied' && $mail->payload['review']['id'] === $review->id;
        });
    }

    /** ...but never about their own words, which is a letter nobody has ever wanted. */
    public function test_nobody_is_emailed_about_their_own_answer(): void
    {
        Mail::fake();

        $product = $this->product('RV-18');
        $review = $this->published($product, 5);

        $this->settings(['premoderate' => false]);

        $this->actingAs(User::findOrFail($review->user_id));
        $this->answer($review, 'One more thought.')->assertRedirect();

        Mail::assertNotQueued(EventMail::class, fn (EventMail $mail) => $mail->event === 'reviews.replied');
    }

    /** A guest reads the thread and is asked to sign in, exactly as for a review. */
    public function test_a_guest_cannot_answer(): void
    {
        $product = $this->product('RV-19');
        $review = $this->published($product, 3);

        $this->answer($review, 'Nonsense')->assertRedirect();

        $this->assertSame(0, $review->fresh()->replies()->count());
    }

    /**
     * Answers follow the shop's moderation setting. A thread that skipped the
     * queue would be the way round it — which is what an advert would use.
     */
    public function test_an_answer_waits_for_moderation_like_a_review(): void
    {
        $product = $this->product('RV-20');
        $review = $this->published($product, 4);

        $this->actingAs($this->someone('passer-by'));
        $this->answer($review, 'Buy at cheapshop.example')->assertRedirect();

        $this->assertSame(0, $review->fresh()->replies()->count());
        $this->assertSame(1, Review::whereNotNull('parent_id')->where('status', Review::PENDING)->count());
    }

    /** @param string $body */
    protected function answer(Review $review, string $body)
    {
        return $this->post('http://localhost/review/'.$review->id.'/reply', [
            'body' => $body,
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The storefront form's own POST: the review fields plus what
     * <x-form-guard> puts in every guarded form. Without the stamp and the
     * empty trap this is refused before it reaches Submit — which is the guard
     * working, and is why every test here goes through this helper.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function write(string $slug, array $payload)
    {
        return $this->post('http://localhost/product/'.$slug.'/review', $payload + [
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ]);
    }

    protected function published(Product $product, int $stars): Review
    {
        $review = Review::create([
            'product_id' => $product->id,
            // A different person each time: one review per person per product
            // is a unique index, and a helper that ignored it would be testing
            // something the shop cannot do.
            'user_id' => $this->someone('reviewer'.$stars.'-'.$product->id)->id,
            'author_name' => 'Reviewer '.$stars,
            'rating' => $stars,
            'status' => Review::PUBLISHED,
            'body' => 'Review with '.$stars,
        ]);

        app(Aggregates::class)->recalculate((int) $product->id);

        return $review;
    }

    protected function criterion(string $code, string $name, array $categoryIds = []): Criterion
    {
        $criterion = Criterion::create([
            'code' => $code, 'name' => ['en' => $name], 'sort' => Criterion::count(), 'enabled' => true,
        ]);

        foreach ($categoryIds as $id) {
            $criterion->categories()->create(['category_id' => $id]);
        }

        app(Criteria::class)->flush();

        return $criterion;
    }

    protected function otherCategory(): Category
    {
        return Category::create(['name' => ['en' => 'Clothes'], 'slug' => 'rv-clothes', 'enabled' => true]);
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        foreach ($values as $key => $value) {
            app(SettingsManager::class)->set('torgash.reviews', $key, $value);
        }
    }

    protected function customer(): User
    {
        return $this->someone('buyer');
    }

    protected function someone(string $handle): User
    {
        return User::firstOrCreate(
            ['email' => $handle.'@example.test'],
            ['name' => 'Review Writer', 'password' => bcrypt('secret')],
        );
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    protected function product(string $sku, array $categoryIds = []): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'name' => ['en' => $sku.' thing'],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $product->categories()->attach($categoryIds ?: [$this->laptops->id]);

        return $product;
    }
}

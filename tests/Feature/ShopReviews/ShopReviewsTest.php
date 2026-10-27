<?php

namespace Tests\Feature\ShopReviews;

use App\Core\Mail\EventMail;
use App\Core\Privacy\PersonalData;
use App\Core\Privacy\Subject;
use App\Core\Settings\SettingsManager;
use App\Core\Support\FormGuard;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\ShopReviews\Models\Criterion;
use Torgash\ShopReviews\Models\Platform;
use Torgash\ShopReviews\Models\ShopReview;
use Torgash\ShopReviews\Stats;

/**
 * Reviews of the shop (torgash.shopreviews): a guest may write one, it waits
 * for a check, the average is made of the criteria given, and the page's
 * numbers move when moderation does.
 */
class ShopReviewsTest extends TestCase
{
    public function test_a_guest_writes_a_review_that_waits_for_a_check(): void
    {
        Mail::fake();
        $this->setting('notify_email', 'shop@example.test');
        [$delivery, $service] = $this->criteria(['delivery', 'service']);

        $this->write([
            'name' => 'Olena', 'email' => 'Olena@Example.test', 'city' => 'Kyiv',
            'body' => 'Fast and polite.', 'pros' => 'Speed', 'cons' => 'Box was dented',
            'criteria' => [$delivery->id => 5, $service->id => 4],
            'recommend' => '1', 'will_return' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $review = ShopReview::sole();
        $this->assertSame(ShopReview::PENDING, $review->status);
        $this->assertSame(4.5, $review->rating);
        $this->assertSame('olena@example.test', $review->email);
        $this->assertTrue($review->recommend);
        $this->assertFalse($review->will_return);
        // A guest is never "a customer of the shop": anybody can type an email.
        $this->assertFalse($review->verified);
        $this->assertSame(2, $review->ratings()->count());

        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === 'shopreviews.posted');

        // Not on the page until published; the email is never on it.
        $html = $this->get('http://localhost/reviews')->assertOk()->getContent();
        $this->assertStringNotContainsString('Fast and polite.', $html);

        $review->update(['status' => ShopReview::PUBLISHED]);
        Stats::forget();
        $html = $this->get('http://localhost/reviews')->assertOk()->getContent();
        $this->assertStringContainsString('Fast and polite.', $html);
        $this->assertStringContainsString('Box was dented', $html);
        $this->assertStringNotContainsString('olena@example.test', $html);
    }

    public function test_a_rating_is_required_and_accounts_only_refuses_guests(): void
    {
        $this->criteria(['delivery']);

        $this->write(['name' => 'X', 'email' => 'x@example.test', 'body' => 'No marks'])
            ->assertSessionHasErrors('rating');

        $this->setting('who_writes', 'accounts');
        $this->write(['name' => 'X', 'email' => 'x@example.test', 'body' => 'Hi', 'criteria' => [Criterion::first()->id => 5]])
            ->assertSessionHasErrors('review');
        $this->assertSame(0, ShopReview::count());
    }

    public function test_with_no_criteria_one_mark_is_asked_and_premoderation_can_be_off(): void
    {
        Criterion::query()->update(['enabled' => false]);
        $this->setting('premoderate', false);

        $this->write(['name' => 'Ivan', 'email' => 'ivan@example.test', 'body' => 'Fine', 'rating' => 3])
            ->assertSessionHasNoErrors();

        $review = ShopReview::sole();
        $this->assertSame(ShopReview::PUBLISHED, $review->status);
        $this->assertSame(3.0, $review->rating);
    }

    public function test_the_numbers_follow_moderation(): void
    {
        $this->published(5, recommend: true);
        $this->published(4, recommend: false);
        $pending = ShopReview::create(['author_name' => 'P', 'body' => 'x', 'rating' => 1, 'status' => ShopReview::PENDING]);

        $stats = app(Stats::class);
        $summary = $stats->summary();
        $this->assertSame(2, $summary['count']);
        $this->assertSame(4.5, $summary['rating']);
        $this->assertSame(50, $summary['recommend']);
        $this->assertSame(1, $summary['spread'][5]);

        $this->actingAs($this->admin())
            ->patch('http://localhost/admin/shop-reviews/'.$pending->id.'/status', ['status' => ShopReview::PUBLISHED])
            ->assertRedirect();

        $summary = $stats->summary();
        $this->assertSame(3, $summary['count']);
        $this->assertEquals(3.3, $summary['rating']);
    }

    public function test_an_answer_publishes_the_review_and_is_sent_to_the_author(): void
    {
        Mail::fake();
        $review = ShopReview::create([
            'author_name' => 'Petro', 'email' => 'petro@example.test', 'body' => 'Late delivery',
            'rating' => 2, 'status' => ShopReview::PENDING,
        ]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/shop-reviews/'.$review->id.'/reply', ['reply' => 'Sorry, we have changed carriers.', 'reply_author' => 'Manager Anna'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(ShopReview::PUBLISHED, $review->status);
        $this->assertSame('Manager Anna', $review->reply_author);
        Mail::assertQueued(EventMail::class, fn (EventMail $mail) => $mail->event === 'shopreviews.replied');

        $html = $this->get('http://localhost/reviews')->getContent();
        $this->assertStringContainsString('Sorry, we have changed carriers.', $html);
        $this->assertStringContainsString('Manager Anna', $html);
    }

    public function test_staff_add_a_review_from_elsewhere_with_its_own_date(): void
    {
        $this->actingAs($this->admin())->post('http://localhost/admin/shop-reviews', [
            'author_name' => 'Maria', 'body' => 'Bought a phone, all good.', 'source' => 'Google',
            'written_at' => '2025-03-14', 'criteria' => [Criterion::where('code', 'service')->value('id') => 5],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $review = ShopReview::sole();
        $this->assertSame(ShopReview::PUBLISHED, $review->status);
        $this->assertSame('2025-03-14', $review->created_at->toDateString());
        $this->assertStringContainsString('From Google', $this->get('http://localhost/reviews')->getContent());
    }

    public function test_the_other_sites_are_shown_with_their_links(): void
    {
        Platform::create(['name' => 'Hotline', 'url' => 'https://hotline.example/shop', 'rating' => 4.7, 'reviews_count' => 1200, 'enabled' => true]);
        Platform::create(['name' => 'Hidden one', 'enabled' => false]);

        $html = $this->get('http://localhost/reviews')->assertOk()->getContent();
        $this->assertStringContainsString('https://hotline.example/shop', $html);
        $this->assertStringContainsString('4.7', $html);
        $this->assertStringNotContainsString('Hidden one', $html);
    }

    public function test_erasing_a_person_unsigns_their_reviews(): void
    {
        $this->published(5);
        $mine = ShopReview::create([
            'author_name' => 'Taras', 'email' => 'taras@example.test', 'city' => 'Lviv',
            'body' => 'Good', 'rating' => 5, 'status' => ShopReview::PUBLISHED,
        ]);

        $erased = app(PersonalData::class)->erase(Subject::of(null, 'taras@example.test'));

        $mine->refresh();
        $this->assertNull($mine->email);
        $this->assertNull($mine->city);
        $this->assertNotSame('Taras', $mine->author_name);
        $this->assertSame('Good', $mine->body);
        $this->assertSame(1, collect($erased)->firstWhere('key', 'shopreviews')['count'] ?? null);
    }

    /** @return list<Criterion> */
    protected function criteria(array $codes): array
    {
        Criterion::whereNotIn('code', $codes)->update(['enabled' => false]);

        return array_map(fn ($code) => Criterion::where('code', $code)->sole(), $codes);
    }

    protected function published(int $rating, ?bool $recommend = null): ShopReview
    {
        $review = ShopReview::create([
            'author_name' => 'A'.$rating, 'body' => 'Text '.$rating, 'rating' => $rating,
            'recommend' => $recommend, 'status' => ShopReview::PUBLISHED,
        ]);
        Stats::forget();

        return $review;
    }

    protected function write(array $payload)
    {
        return $this->post('http://localhost/reviews', $payload + [
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
            FormGuard::TRAP => '',
        ]);
    }

    protected function setting(string $key, mixed $value): void
    {
        app(SettingsManager::class)->set('torgash.shopreviews', $key, $value);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}

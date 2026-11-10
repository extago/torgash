<?php

namespace Tests\Feature\Core;

use App\Core\Http\ForeignHost;
use App\Core\Mail\EventMail;
use App\Core\Support\FormGuard;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\RunsInOctaneWorker;
use Tests\TestCase;

/**
 * `Host` is a line the sender writes, and a link in a letter must not start
 * from it: "I lost my password" posted with somebody else's domain in that
 * line made the shop send a real letter whose button led to that domain.
 */
class ForeignHostTest extends TestCase
{
    use RunsInOctaneWorker;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://shop.example', 'torgash.verification.check_mail_domain' => false]);
        Mail::fake();
        RateLimiter::clear('verification');
    }

    protected function askForACode(string $host, User $customer): void
    {
        $this->post('http://'.$host.'/account/code', [
            'for' => 'password_reset',
            'login' => $customer->email,
            FormGuard::STAMP => Crypt::encryptString((string) (time() - 30)),
        ]);
    }

    /** The button of the code letter that went out. */
    protected function codeLink(): string
    {
        $link = '';

        Mail::assertSent(EventMail::class, function (EventMail $mail) use (&$link) {
            $link = (string) ($mail->payload['link'] ?? '');

            return true;
        });

        return $link;
    }

    protected function customer(): User
    {
        return User::factory()->create(['email' => 'buyer-'.uniqid().'@example.com']);
    }

    /** A route on the worker's own application. */
    protected function workerRoute(string $uri, \Closure $action): void
    {
        $router = $this->octaneApp()['router'];
        $router->get($uri, $action);
        $router->getRoutes()->refreshNameLookups();
    }

    protected function secondStorefront(): void
    {
        Storefront::create([
            'code' => 'second', 'name' => 'Second', 'domains' => ['second.test'], 'theme' => 'default',
            'default_locale' => 'en', 'locales' => ['en'], 'default_currency' => 'USD', 'currencies' => ['USD'],
            'is_default' => false, 'enabled' => true,
        ]);
    }

    public function test_the_button_in_a_code_letter_leads_to_the_shop_whatever_the_host_said(): void
    {
        $this->askForACode('evil.example', $this->customer());

        $this->assertStringStartsWith('https://shop.example/', $this->codeLink());
    }

    public function test_a_managers_password_letter_leads_to_the_shop_whatever_the_host_said(): void
    {
        $manager = User::factory()->create(['email' => 'manager-'.uniqid().'@example.com']);
        $manager->assignRole('admin');

        $this->post('http://evil.example/admin/password/forgot', ['email' => $manager->email]);

        $link = '';
        Mail::assertQueued(EventMail::class, function (EventMail $mail) use (&$link) {
            $link = (string) ($mail->payload['link'] ?? '');

            return true;
        });

        $this->assertStringStartsWith('https://shop.example/admin/password/reset/', $link);
    }

    public function test_a_storefronts_own_address_keeps_its_links(): void
    {
        $this->secondStorefront();

        $this->askForACode('second.test', $this->customer());

        $this->assertStringStartsWith('http://second.test/', $this->codeLink());
    }

    public function test_what_counts_as_the_shops_own_address(): void
    {
        $this->secondStorefront();
        $door = app(ForeignHost::class);

        foreach (['shop.example', 'WWW.shop.example', 'm.shop.example', 'second.test', 'www.second.test', 'localhost', '127.0.0.1'] as $own) {
            $this->assertTrue($door->known($own), $own.' is the shop\'s');
        }

        foreach (['evil.example', 'shop.example.evil.test', 'evilshop.example', 'example', '203.0.113.7'] as $foreign) {
            $this->assertFalse($door->known($foreign), $foreign.' is not');
        }
    }

    public function test_a_shop_with_no_address_of_its_own_writes_what_it_was_asked_on(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->askForACode('shop.as.deployed', $this->customer());

        $this->assertStringStartsWith('http://shop.as.deployed/', $this->codeLink());
    }

    public function test_a_host_nobody_named_is_still_answered(): void
    {
        $this->get('http://203.0.113.7/account/login')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://shop.example/account/login"', false)
            ->assertDontSee('203.0.113.7', false);
    }

    public function test_what_a_foreign_host_was_told_does_not_stay_for_the_next_request(): void
    {
        $this->workerRoute('/__worker/where', fn () => url('/somewhere'));

        $home = rtrim((string) $this->octaneApp()['config']->get('app.url'), '/');

        $responses = $this->serveInWorker([
            $this->workerGet('http://evil.example/__worker/where'),
            $this->workerGet('http://localhost/__worker/where'),
        ]);

        $this->assertSame($home.'/somewhere', $responses[0]->getContent());
        $this->assertSame('http://localhost/somewhere', $responses[1]->getContent());
    }
}

<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Tests\TestCase;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\EximProfile;
use Torgash\Exim\Renderers\CsvRenderer;

/**
 * A feed address is a secret that leaks — forwarded, logged, pasted into a
 * chat. So it alone opens only the shop's public data, and what an export
 * writes into a CSV never runs as a formula in the manager's spreadsheet.
 */
class FeedSafetyTest extends TestCase
{
    protected function profile(string $entity, array $extra = []): EximProfile
    {
        return EximProfile::create(['type' => 'export', 'name' => ['en' => $entity.' feed'], 'entity' => $entity, 'format' => 'csv',
            'mode' => 'schema', 'public_token' => bin2hex(random_bytes(16)), 'enabled' => true, ...$extra]);
    }

    public function test_the_catalogue_goes_by_address_and_is_not_indexed(): void
    {
        $this->get('http://localhost/exim/feed/'.$this->profile('product')->public_token)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_orders_and_enquiries_by_address_alone_are_refused(): void
    {
        foreach (['order', 'enquiry', 'review'] as $entity) {
            $this->get('http://localhost/exim/feed/'.$this->profile($entity)->public_token)->assertForbidden();
        }
    }

    public function test_customer_data_opens_with_the_feeds_login(): void
    {
        $profile = $this->profile('order', ['basic_auth_user' => 'crm', 'basic_auth_pass' => 'secret-pass']);
        $url = 'http://localhost/exim/feed/'.$profile->public_token;

        $this->get($url)->assertUnauthorized();
        $this->get($url, ['PHP_AUTH_USER' => 'crm', 'PHP_AUTH_PW' => 'wrong'])->assertUnauthorized();
        $this->get($url, ['PHP_AUTH_USER' => 'crm', 'PHP_AUTH_PW' => 'secret-pass'])->assertOk();
    }

    public function test_the_form_says_why_a_customer_feed_is_closed(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        $this->get('http://localhost/admin/exim/profiles/'.$this->profile('order')->id.'/edit')->assertOk()
            ->assertSee(__('This export holds customers\' data: the feed stays closed until you set a login and password below.'));
        $this->get('http://localhost/admin/exim/profiles/'.$this->profile('product')->id.'/edit')->assertOk()
            ->assertDontSee(__('This export holds customers\' data: the feed stays closed until you set a login and password below.'));
    }

    public function test_a_formula_is_written_as_text_and_read_back_unchanged(): void
    {
        foreach (['=HYPERLINK("http://x","y")', '+1+1', '-2+3', '@SUM(A1)', "\t=1"] as $value) {
            $written = CsvRenderer::guard($value);
            $this->assertSame("'".$value, $written);
            $this->assertSame($value, ImportRunner::unguard($written));
        }

        // Numbers stay numbers, and the shop's own apostrophes stay put.
        foreach (['-5', '+3', '-1.5', 'Rock\'n\'roll', "'quoted'", 'plain'] as $value) {
            $this->assertSame($value, CsvRenderer::guard($value));
            $this->assertSame($value, ImportRunner::unguard($value));
        }
    }

    public function test_an_order_export_does_not_carry_a_live_formula(): void
    {
        $line = (new \ReflectionMethod(CsvRenderer::class, 'line'))->invoke(new CsvRenderer, ['=cmd|calc', 'Ann', -5]);

        $this->assertSame("'=cmd|calc,Ann,-5\n", $line);
    }
}

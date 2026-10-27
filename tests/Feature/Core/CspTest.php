<?php

namespace Tests\Feature\Core;

use App\Core\Http\Csp;
use Tests\TestCase;

/**
 * The content security policy (PLAN §53), enforced by default: every page
 * names this request's nonce, every script the engine prints carries it,
 * the nonce changes with every request, and what a browser reports is
 * written down once a day per violation.
 */
class CspTest extends TestCase
{
    protected function nonceOf(\Illuminate\Testing\TestResponse $response): string
    {
        $this->assertTrue(preg_match("~'nonce-([A-Za-z0-9+/=]+)'~", (string) $response->headers->get('Content-Security-Policy'), $m) === 1);

        return $m[1];
    }

    public function test_every_script_on_a_page_carries_the_nonce_its_policy_names(): void
    {
        $this->get('http://localhost/');
        $response = $this->get('http://localhost/');
        $nonce = $this->nonceOf($response);

        preg_match_all('~<script\b[^>]*>~i', $response->getContent(), $tags);
        $unsigned = array_filter($tags[0], fn ($tag) => ! str_contains($tag, 'nonce="'.$nonce.'"') && ! str_contains($tag, 'application/ld+json'));

        $this->assertNotEmpty($tags[0]);
        $this->assertSame([], array_values($unsigned));
        $this->assertNotSame($nonce, $this->nonceOf($this->get('http://localhost/')), 'a nonce that repeats is no nonce');
    }

    public function test_a_file_or_a_json_answer_carries_no_policy(): void
    {
        $this->get('http://localhost/sitemap.xml')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_the_shops_own_code_is_signed_and_markup_without_a_tag_is_left_alone(): void
    {
        $csp = app(Csp::class);

        $this->assertSame('<script nonce="'.$csp->nonce().'" src="x.js"></script><p>hi</p>', $csp->sign('<script src="x.js"></script><p>hi</p>'));
        $this->assertSame('<script nonce="other">1</script>', $csp->sign('<script nonce="other">1</script>'));

        // A tag written INSIDE a script's text is the script's string, not a tag.
        $old = '<script>document.write("<script src=\'c.js\'></scr"+"ipt>");</script><script>2</script>';
        $this->assertSame(
            '<script nonce="'.$csp->nonce().'">document.write("<script src=\'c.js\'></scr"+"ipt>");</script><script nonce="'.$csp->nonce().'">2</script>',
            $csp->sign($old),
        );
    }

    /** Enforced by default; a shop with an old module may only report, or switch it off. */
    public function test_the_shop_chooses_whether_the_policy_is_enforced(): void
    {
        $this->get('http://localhost/')->assertHeader('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');

        app(\App\Core\Settings\SettingsManager::class)->set('core', Csp::SETTING, 'report');
        $this->get('http://localhost/')->assertHeader('Content-Security-Policy-Report-Only')->assertHeaderMissing('Content-Security-Policy');

        app(\App\Core\Settings\SettingsManager::class)->set('core', Csp::SETTING, 'off');
        $this->get('http://localhost/')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    /** An inline handler is inline script: under the policy it does not run. */
    public function test_no_template_has_an_inline_handler(): void
    {
        $offenders = [];
        $finder = \Symfony\Component\Finder\Finder::create()->files()->name('*.blade.php')
            ->in([resource_path('views'), base_path('themes'), base_path('modules')]);

        foreach ($finder as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
                if (preg_match('/(^|\s)on[a-z]+="/', $line) || str_contains($line, 'href="javascript:')) {
                    $offenders[] = $file->getRelativePathname().':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use x-on: (Alpine) or a data-* action from assets/js/actions.js.');
    }

    public function test_a_report_is_written_once_a_day_per_violation(): void
    {
        // The suite's own folder, never the shop's log.
        $this->app->useStoragePath(storage_path('framework/testing/csp-'.bin2hex(random_bytes(4))));
        $log = storage_path('logs/csp-'.now()->format('Y-m-d').'.log');
        $before = is_file($log) ? substr_count((string) file_get_contents($log), "\n") : 0;
        $report = ['csp-report' => ['violated-directive' => 'script-src-attr', 'blocked-uri' => 'inline', 'document-uri' => 'http://localhost/p?secret=1', 'line-number' => 7, 'script-sample' => 'probe-'.uniqid()]];

        $this->postJson('http://localhost/csp-report', $report)->assertNoContent();
        $this->postJson('http://localhost/csp-report', $report)->assertNoContent();

        $text = (string) file_get_contents($log);
        $this->assertSame($before + 1, substr_count($text, "\n"));
        $this->assertStringNotContainsString('secret=1', $text, 'a page address is kept without its query');
    }
}

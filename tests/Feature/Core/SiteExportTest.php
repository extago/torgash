<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `torgash:site-export`: the modules, as documentation pages of the engine's
 * website — written from the manifests and the modules' own docs, so the
 * site never describes a module by hand.
 */
class SiteExportTest extends TestCase
{
    protected string $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = storage_path('framework/testing/site-'.uniqid());
        File::ensureDirectoryExists($this->site);
        file_put_contents($this->site.'/hugo.toml', "title = 'test'\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->site);
        parent::tearDown();
    }

    protected function page(string $slug, string $locale): array
    {
        $raw = (string) file_get_contents($this->site.'/content/solutions/torgash/docs/modules/'.$slug.'/index.'.$locale.'.md');
        [, $json, $body] = explode("---\n", $raw, 3);

        return [json_decode($json, true), $body];
    }

    public function test_every_module_gets_a_page_in_both_languages_but_the_example(): void
    {
        $this->artisan('torgash:site-export', ['site' => $this->site])->assertSuccessful();

        $dir = $this->site.'/content/solutions/torgash/docs/modules';
        $this->assertFileExists($dir.'/_index.ru.md');
        $this->assertFileExists($dir.'/catalog/index.en.md');
        $this->assertFileExists($dir.'/catalog/index.ru.md');
        $this->assertDirectoryDoesNotExist($dir.'/hello');
    }

    public function test_a_page_carries_what_the_engine_knows_and_the_modules_own_docs(): void
    {
        $this->artisan('torgash:site-export', ['site' => $this->site])->assertSuccessful();

        [$front, $body] = $this->page('novaposhta', 'ru');

        $this->assertSame('torgash.novaposhta', $front['id']);
        $this->assertSame(['UA'], $front['regions']);
        $this->assertSame([], $front['sets'], 'a country\'s module is in no set without the country');
        $this->assertContains('shipping', array_column($front['requires'], 'slug'));
        $this->assertTrue($front['has_docs']);
        $this->assertTrue($front['docs_translated']);
        $this->assertStringContainsString('## ', $body, 'each docs tab is a section');
        $this->assertStringNotContainsString("\n# ", "\n".$body, 'no second page title inside the page');
        $this->assertSame(1, preg_match('//u', $body), 'the Cyrillic text is whole UTF-8');

        [$catalog] = $this->page('catalog', 'en');
        $this->assertSame(['minimal', 'store'], $catalog['sets']);
    }
}

<?php

namespace Tests\Feature\Core;

use App\Core\Updates\GitHubPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * `torgash:release --github`: a draft, its files, and only then published — a
 * shop asking in between is never offered a version whose package is not there
 * yet. A version already on GitHub is refused, never replaced.
 */
class GitHubPublisherTest extends TestCase
{
    protected array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['torgash.updates.github' => 'extago/torgash', 'torgash.updates.github_publish_token' => 'T']);

        foreach (['torgash-0.2.1.zip', 'torgash-0.2.1.zip.sha256', 'torgash-0.2.1.zip.sig'] as $name) {
            $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;
            file_put_contents($path, 'x');
            $this->files[] = $path;
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);

        parent::tearDown();
    }

    public function test_a_draft_gets_its_files_and_is_then_published(): void
    {
        Http::fake([
            'api.github.com/repos/extago/torgash/releases/tags/v0.2.1' => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/extago/torgash/releases' => Http::response(['id' => 7]),
            'uploads.github.com/*' => Http::response(['id' => 1]),
            'api.github.com/repos/extago/torgash/releases/7' => Http::response(['html_url' => 'https://github.com/extago/torgash/releases/tag/v0.2.1']),
        ]);

        $result = app(GitHubPublisher::class)->publish('0.2.1', $this->files, 'Worker mode');

        $this->assertSame(['torgash-0.2.1.zip', 'torgash-0.2.1.zip.sha256', 'torgash-0.2.1.zip.sig'], $result['assets']);

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $create = $sent->first(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/releases'));
        $this->assertTrue($create['draft']);
        $this->assertSame('v0.2.1', $create['tag_name']);
        $this->assertSame('Worker mode', $create['body']);

        $publish = $sent->last();
        $this->assertSame('PATCH', $publish->method(), 'published only after every file is up');
        $this->assertFalse($publish['draft']);
    }

    public function test_a_version_already_on_github_is_refused(): void
    {
        Http::fake(['api.github.com/repos/extago/torgash/releases/tags/v0.2.1' => Http::response(['html_url' => 'https://github.com/extago/torgash/releases/tag/v0.2.1'])]);

        $this->expectException(RuntimeException::class);
        app(GitHubPublisher::class)->publish('0.2.1', $this->files);
    }

    public function test_a_failed_upload_takes_the_draft_away(): void
    {
        Http::fake([
            'api.github.com/repos/extago/torgash/releases/tags/v0.2.1' => Http::response([], 404),
            'api.github.com/repos/extago/torgash/releases' => Http::response(['id' => 7]),
            'uploads.github.com/*' => Http::response(['message' => 'boom'], 500),
            'api.github.com/repos/extago/torgash/releases/7' => Http::response([], 204),
        ]);

        try {
            app(GitHubPublisher::class)->publish('0.2.1', $this->files);
            $this->fail('a failed upload must not publish');
        } catch (RuntimeException) {
            Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/releases/7'));
            Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        }
    }
}

<?php

namespace Tests\Feature\Guard;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Guard\Files\Uploads;

/**
 * An executable file where the pictures are supposed to be (PLAN §26.3).
 *
 * Two halves, and the tests are about the two ways each one lies. The scan
 * lies by checking the last extension only (`pic.php.jpg` is a jpg to a naive
 * look and a php to more than one web server) and by reporting the engine's own
 * files every night until nobody reads it. The self-test lies by calling a
 * server "safe" when it never actually asked it anything.
 */
class UploadsTest extends TestCase
{
    protected string $root;

    /** @var list<string> */
    protected array $planted = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/public');

        if (! is_dir($this->root)) {
            mkdir($this->root, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->planted as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    protected function plant(string $name, string $content = 'x'): string
    {
        $path = $this->root.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);
        $this->planted[] = $path;

        return $path;
    }

    /** @return list<string> the titles the scan reported */
    protected function scanned(): array
    {
        return array_map(
            fn (array $violation) => $violation['title'],
            iterator_to_array((new Uploads)->scan(50), false),
        );
    }

    // ------------------------------------------------------------- the scan

    public function test_an_uploaded_script_is_found(): void
    {
        $this->plant('guard-test-shell.php', '<?php echo 1;');

        $this->assertContains('storage/app/public/guard-test-shell.php', $this->scanned());
    }

    /**
     * The rule CommerceML learned about uploaded names, and the reason the
     * check is per SEGMENT: this is a `.jpg` to anything that looks at the last
     * extension, and a `.php` to more than one web server.
     */
    public function test_a_double_extension_is_found(): void
    {
        $this->plant('guard-test-photo.php.jpg', 'GIF89a');

        $this->assertContains('storage/app/public/guard-test-photo.php.jpg', $this->scanned());
    }

    public function test_code_inside_an_innocent_name_is_found(): void
    {
        $this->plant('guard-test-notes.txt', 'hello'.PHP_EOL.'<?php system($_GET[1]); ?>');

        $this->assertContains('storage/app/public/guard-test-notes.txt', $this->scanned());
    }

    public function test_an_ordinary_picture_is_not_reported(): void
    {
        $this->plant('guard-test-photo.jpg', 'GIF89a');

        $this->assertNotContains('storage/app/public/guard-test-photo.jpg', $this->scanned());
    }

    /**
     * The engine's own front controller must not be reported every night, or
     * the list becomes something nobody reads — which is the same as not having
     * it. Modules that legitimately write one add it through the filter.
     */
    public function test_the_engines_own_files_are_not_reported(): void
    {
        $this->assertNotContains('public/index.php', $this->scanned());
    }

    public function test_a_module_can_declare_its_own_executable_file(): void
    {
        $path = $this->plant('guard-test-declared.php', '<?php echo 1;');

        $this->assertContains('storage/app/public/guard-test-declared.php', $this->scanned());

        hook_listen('guard.files.expected', fn (array $paths) => [...$paths, $path]);

        $this->assertNotContains('storage/app/public/guard-test-declared.php', $this->scanned());
    }

    // -------------------------------------------------------- the self-test

    /** The hole the whole thing exists to find. */
    public function test_a_server_that_executes_the_probe_is_reported(): void
    {
        Http::fake(['*' => Http::response('TORGASH-GUARD-EXECUTED', 200)]);

        $problems = (new Uploads)->selfTest();

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('EXECUTED', $problems[0]['detail'][0]);
    }

    public function test_a_server_that_serves_the_source_is_not_a_problem(): void
    {
        Http::fake(['*' => Http::response("<?php echo 'x';", 200)]);

        $this->assertSame([], (new Uploads)->selfTest());
    }

    public function test_a_server_that_refuses_outright_is_not_a_problem(): void
    {
        Http::fake(['*' => Http::response('', 403)]);

        $this->assertSame([], (new Uploads)->selfTest());
    }

    /**
     * The probe must never outlive the test that wrote it: leaving one behind
     * would be this module planting exactly what it warns about.
     */
    public function test_the_probe_is_always_removed(): void
    {
        Http::fake(['*' => Http::response('TORGASH-GUARD-EXECUTED', 200)]);

        (new Uploads)->selfTest();

        $left = glob($this->root.DIRECTORY_SEPARATOR.'probe-*') ?: [];

        $this->assertSame([], $left);
    }

    /** Including when the request itself blows up. */
    public function test_the_probe_is_removed_even_when_the_request_fails(): void
    {
        Http::fake(fn () => throw new \RuntimeException('no route to host'));

        $problems = (new Uploads)->selfTest();

        $this->assertNotSame([], $problems);
        $this->assertSame([], glob($this->root.DIRECTORY_SEPARATOR.'probe-*') ?: []);
    }
}

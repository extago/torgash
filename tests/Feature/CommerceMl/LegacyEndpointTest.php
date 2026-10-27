<?php

namespace Tests\Feature\CommerceMl;

use App\Models\User;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\CommerceMl\LegacyEndpoint;

/**
 * `/1c_exchange.php` — the address 1C's own instructions hard-code.
 *
 * Two separate promises, and the second is the one that earns the file's right
 * to sit in public/ at all: it answers, and it goes away again. A module is
 * allowed to leave a trace outside its own directory only while it is
 * installed.
 */
class LegacyEndpointTest extends TestCase
{
    protected LegacyEndpoint $endpoint;

    /** Whatever was there before this test — the dev shop has the real file. */
    protected ?string $saved = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->endpoint = new LegacyEndpoint;

        if ($this->endpoint->exists()) {
            $this->saved = file_get_contents($this->endpoint->path());
            unlink($this->endpoint->path());
        }
    }

    protected function tearDown(): void
    {
        if ($this->endpoint->exists()) {
            unlink($this->endpoint->path());
        }

        if ($this->saved !== null) {
            file_put_contents($this->endpoint->path(), $this->saved);
        }

        parent::tearDown();
    }

    public function test_the_address_reaches_the_exchange(): void
    {
        [, $token] = ApiToken::issue(User::role('admin')->firstOrFail(), '1C');

        // The route, not the file: a request that gets this far is one the web
        // server let through, and letting it through is the file's whole job.
        $this->withBasicAuth('1c', $token)
            ->get('http://localhost/1c_exchange.php?type=catalog&mode=checkauth')
            ->assertOk()
            ->assertSee('success');
    }

    public function test_installing_writes_the_file_and_uninstalling_removes_it(): void
    {
        $this->assertTrue($this->endpoint->install());
        $this->assertTrue($this->endpoint->isOurs());

        // It has to be a front controller, and it has to correct SCRIPT_NAME —
        // without that Symfony reads the whole path as the script's own name
        // and 1C's request arrives at the homepage.
        $written = file_get_contents($this->endpoint->path());
        $this->assertStringContainsString("require __DIR__.'/index.php'", $written);
        $this->assertStringContainsString("\$_SERVER['SCRIPT_NAME'] = '/index.php'", $written);

        $this->assertTrue($this->endpoint->remove());
        $this->assertFalse($this->endpoint->exists());
    }

    /**
     * A shop mid-migration still has the old engine's script at that address.
     * Overwriting it would break the shop it is moving away from, and deleting
     * it on uninstall would delete somebody else's file.
     */
    public function test_a_file_that_is_not_ours_is_neither_overwritten_nor_deleted(): void
    {
        file_put_contents($this->endpoint->path(), '<?php // the previous engine');

        $this->assertFalse($this->endpoint->install());
        $this->assertFalse($this->endpoint->remove());
        $this->assertSame('<?php // the previous engine', file_get_contents($this->endpoint->path()));
    }

    public function test_removing_when_there_is_nothing_there_is_not_a_failure(): void
    {
        $this->assertTrue($this->endpoint->remove());
    }

    public function test_the_screen_offers_the_file_and_takes_it_back(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)
            ->post('http://localhost/admin/commerceml/legacy', ['action' => 'install'])
            ->assertRedirect();
        $this->assertTrue($this->endpoint->isOurs());

        $this->actingAs($admin)
            ->post('http://localhost/admin/commerceml/legacy', ['action' => 'remove'])
            ->assertRedirect();
        $this->assertFalse($this->endpoint->exists());
    }
}

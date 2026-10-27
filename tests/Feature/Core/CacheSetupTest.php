<?php

namespace Tests\Feature\Core;

use App\Core\Performance\CacheSetup;
use App\Core\Performance\FallbackState;
use App\Core\Support\EnvFile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin → Cache and sessions: a choice is tried before it is written, it is
 * written into .env without disturbing the rest of the file, and "Redis"
 * means Redis with the database behind it — a Redis that does not answer
 * slows the shop down once and is then left alone, never stops it.
 *
 * Nothing here needs a Redis server: the one that "does not answer" is a port
 * nobody listens on, which is exactly the case the fallback exists for.
 */
class CacheSetupTest extends TestCase
{
    protected string $env;

    protected function setUp(): void
    {
        parent::setUp();

        // Never the shop's own .env.
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->env = storage_path('framework/testing/env-'.uniqid());
        file_put_contents($this->env, "APP_NAME=Torgash\n# a comment stays\nCACHE_STORE=database\nSESSION_DRIVER=file\nREDIS_PASSWORD=null\n");
        $this->app->instance(EnvFile::class, new EnvFile($this->env));
    }

    protected function tearDown(): void
    {
        @unlink($this->env);
        app(FallbackState::class)->forget('redis');

        parent::tearDown();
    }

    public function test_env_file_replaces_named_keys_and_keeps_everything_else(): void
    {
        $env = new EnvFile($this->env);
        $env->set(['CACHE_STORE' => 'redis-fallback', 'REDIS_HOST' => '10.0.0.5', 'REDIS_PASSWORD' => 'p$ss w#rd']);

        $raw = file_get_contents($this->env);
        $this->assertStringContainsString("APP_NAME=Torgash\n# a comment stays\nCACHE_STORE=redis-fallback\n", $raw);
        $this->assertStringContainsString("REDIS_HOST=10.0.0.5\n", $raw);
        // Single quotes: literal in phpdotenv, so `$ss` is not expanded.
        $this->assertStringContainsString("REDIS_PASSWORD='p\$ss w#rd'", $raw);
        $this->assertSame('p$ss w#rd', $env->get('REDIS_PASSWORD'));
        $this->assertSame(1, substr_count($raw, 'CACHE_STORE='));
    }

    public function test_redis_that_does_not_answer_falls_back_to_the_database_and_is_then_skipped(): void
    {
        config(['database.redis.cache.port' => 6391, 'database.redis.cache.max_retries' => 0, 'database.redis.cache.timeout' => 0.5]);
        app(FallbackState::class)->forget('redis');

        $store = Cache::store('redis-fallback');
        $store->put('fallback-test', 'kept', 60);

        $this->assertSame('kept', Cache::store('database')->get('fallback-test'));
        $this->assertTrue(app(FallbackState::class)->isDown('redis', 30));

        // Marked down: the next reads do not wait for Redis at all.
        $started = hrtime(true);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('kept', $store->get('fallback-test'));
        }
        $this->assertLessThan(300, (hrtime(true) - $started) / 1e6);

        $this->assertNotNull(app(CacheSetup::class)->lastOutage());
    }

    public function test_the_screen_checks_before_it_saves_and_refuses_what_does_not_answer(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/cache')
            ->assertOk()
            ->assertSee(__('Cache and sessions'));

        // A Redis nobody runs: nothing is written.
        $this->actingAs($admin)->post('http://localhost/admin/cache', [
            'cache' => 'redis', 'sessions' => 'file', 'redis_host' => '127.0.0.1', 'redis_port' => 6391, 'action' => 'save',
        ])->assertRedirect(route('admin.cache'))
            ->assertSessionHas('probes', fn ($probes) => ! $probes['redis']['ok']);
        $this->assertStringContainsString("CACHE_STORE=database\n", file_get_contents($this->env));

        // The file cache answers: written, the rest of the file untouched.
        $this->actingAs($admin)->post('http://localhost/admin/cache', [
            'cache' => 'file', 'sessions' => 'file', 'action' => 'save',
        ])->assertRedirect(route('admin.cache'))
            ->assertSessionHas('status');

        $raw = file_get_contents($this->env);
        $this->assertStringContainsString("CACHE_STORE=file\n", $raw);
        $this->assertStringContainsString('# a comment stays', $raw);
        $this->assertStringNotContainsString('REDIS_HOST', $raw);
    }

    public function test_the_screen_is_behind_its_own_permission(): void
    {
        $role = Role::findOrCreate('cache-test-manager');
        $role->givePermissionTo('admin.access');
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->get('http://localhost/admin/cache')->assertForbidden();

        $role->givePermissionTo('cache.manage');
        $this->actingAs($user->fresh())->get('http://localhost/admin/cache')->assertOk();
    }

    /**
     * Valkey answers INFO with `redis_version:7.2.4`, the version it forked
     * from, so the screen names it by `server_name` / `valkey_version`;
     * phpredis returns INFO flat, predis by section.
     */
    public function test_the_server_is_named_redis_or_valkey(): void
    {
        $this->assertSame('Redis 7.4.2', CacheSetup::serverName(['redis_version' => '7.4.2']));
        $this->assertSame('Redis 7.4.2', CacheSetup::serverName(['Server' => ['redis_version' => '7.4.2']]));
        $this->assertSame('Valkey 8.1.1', CacheSetup::serverName(['redis_version' => '7.2.4', 'server_name' => 'valkey', 'valkey_version' => '8.1.1']));
        $this->assertSame('Valkey 8.1.1', CacheSetup::serverName(['Server' => ['redis_version' => '7.2.4', 'valkey_version' => '8.1.1']]));
        $this->assertSame('Valkey', CacheSetup::serverName(['redis_version' => '7.2.4', 'server_name' => 'valkey']));
        $this->assertSame('', CacheSetup::serverName([]));
    }
}

<?php

namespace Tests\Feature\Core;

use App\Core\CoreServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Psy\Configuration;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Tinker where PHP's own CLI is not the one running it — FrankenPHP's
 * `php-cli`, which has pcntl and not cli_set_process_title(), the function
 * PsySH's forked loop calls (CoreServiceProvider::tinkerWithoutFork).
 */
class TinkerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['PSYSH_CONFIG']);

        parent::tearDown();
    }

    /** The file handed over says "do not fork" in PsySH's own words. */
    public function test_the_settings_handed_to_psysh_switch_its_fork_off(): void
    {
        $config = new Configuration(['configFile' => CoreServiceProvider::PSYSH_CONFIG]);

        // The property, not usePcntl(): without pcntl (Windows) that answers
        // false whatever the file says.
        $this->assertFalse((fn () => $this->usePcntl)->call($config));
    }

    /** Handed over only where the function is missing; PHP's own CLI forks as before. */
    public function test_the_settings_are_handed_over_only_where_the_function_is_missing(): void
    {
        unset($_SERVER['PSYSH_CONFIG']);

        event(new CommandStarting('tinker', new ArrayInput([]), new NullOutput));

        if (function_exists('cli_set_process_title')) {
            $this->assertArrayNotHasKey('PSYSH_CONFIG', $_SERVER);
        } else {
            $this->assertSame(CoreServiceProvider::PSYSH_CONFIG, $_SERVER['PSYSH_CONFIG']);
        }

        event(new CommandStarting('migrate', new ArrayInput([]), new NullOutput));
        $_SERVER['PSYSH_CONFIG'] = '/the/servers/own.php';
        event(new CommandStarting('tinker', new ArrayInput([]), new NullOutput));
        $this->assertSame('/the/servers/own.php', $_SERVER['PSYSH_CONFIG'], 'one the server set is left alone');
    }
}

<?php

namespace Tests\Unit;

use App\Core\Support\Php;
use PHPUnit\Framework\TestCase;

/**
 * How the engine starts PHP from PHP (App\Core\Support\Php): never the bare
 * PHP_BINARY, which under FrankenPHP is `frankenphp` and answers
 * `frankenphp artisan …` with "unknown command".
 */
class PhpCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_BINARY');
        parent::tearDown();
    }

    public function test_the_environment_variable_wins(): void
    {
        putenv('PHP_BINARY=/usr/local/bin/php');

        $this->assertSame(['/usr/local/bin/php'], Php::command('/usr/local/bin/frankenphp'));
    }

    public function test_frankenphp_is_started_through_its_php_cli(): void
    {
        putenv('PHP_BINARY');

        $this->assertSame(['/usr/local/bin/frankenphp', 'php-cli'], Php::command('/usr/local/bin/frankenphp'));
        $this->assertSame('/usr/local/bin/frankenphp php-cli', implode(' ', Php::command('/usr/local/bin/frankenphp')));
    }

    public function test_an_ordinary_php_is_itself(): void
    {
        putenv('PHP_BINARY');

        $this->assertCount(1, Php::command());
        $this->assertNotSame('', Php::command()[0]);
    }
}

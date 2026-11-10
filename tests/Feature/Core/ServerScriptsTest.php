<?php

namespace Tests\Feature\Core;

use App\Core\Support\ServerScripts;
use Tests\TestCase;
use Torgash\Attachments\Documents;
use Torgash\Files\Library;
use Torgash\Guard\Files\Uploads;
use Torgash\Options\Files;

/**
 * What a web server may hand to an interpreter is one fact, and it used to be
 * written down four times with four different answers.
 */
class ServerScriptsTest extends TestCase
{
    public function test_every_refusal_knows_every_script_a_server_may_run(): void
    {
        foreach (['php', 'phps', 'pht', 'phar', 'phtml', 'cgi', 'pl', 'py', 'sh', 'htaccess'] as $script) {
            $this->assertContains($script, ServerScripts::EXTENSIONS);
        }

        foreach ([Documents::NEVER, Library::NEVER, Files::NEVER, Uploads::EXECUTABLE] as $list) {
            $this->assertSame([], array_values(array_diff(ServerScripts::EXTENSIONS, $list)));
        }
    }
}

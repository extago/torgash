<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

/**
 * Bytes that are not UTF-8 used to reach Postgres and come back as a 500 —
 * on the search page, from a single mangled link.
 */
class ValidUtf8Test extends TestCase
{
    public function test_a_search_for_bytes_that_are_not_utf8_answers_instead_of_failing(): void
    {
        // %EE%F3 is "ну" in cp1251 — an old link, a scanner, a broken script.
        $this->get('http://localhost/search?q=%EE%F3')->assertOk();
    }

    public function test_a_parameter_is_scrubbed_rather_than_dropped(): void
    {
        $this->get('http://localhost/search?q=%D0%BD%D1%83%EE')->assertOk();

        $this->assertSame('ну?', request()->query('q'), 'the good part stays, the bad byte is marked');
    }

    /** Answered by the framework before this middleware — asserted so it stays that way. */
    public function test_a_path_that_is_not_utf8_never_reaches_a_controller(): void
    {
        $this->get('http://localhost/product/%EE%F3')->assertStatus(400);
    }

    public function test_ordinary_cyrillic_is_left_exactly_as_it_was(): void
    {
        $this->get('http://localhost/search?q=%D0%BD%D0%BE%D1%83%D1%82')->assertOk();

        $this->assertSame('ноут', request()->query('q'));
    }
}

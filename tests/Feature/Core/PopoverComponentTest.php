<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Core's popover: a button and a panel tied by one id — popovertarget for
 * the browser's Popover API, the same name as the CSS anchor — with the
 * trigger written after the content (a slot before the content leaves a
 * buffer open).
 */
class PopoverComponentTest extends TestCase
{
    public function test_the_button_and_its_panel_are_tied_by_one_name(): void
    {
        $html = Blade::render(
            '<x-popover id="call-us" class="font-medium" panel-class="p-2" placement="top">'
            .'<a href="tel:+380441112233">Call</a>'
            .'<x-slot:trigger>+38 044 111 22 33</x-slot:trigger>'
            .'</x-popover>'
        );

        $this->assertStringContainsString('popovertarget="call-us"', $html);
        $this->assertStringContainsString('anchor-name: --call-us', $html);
        $this->assertStringContainsString('id="call-us" popover', $html);
        $this->assertStringContainsString('position-anchor: --call-us', $html);
        $this->assertStringContainsString('data-placement="top"', $html);
        $this->assertStringContainsString('class="cursor-pointer text-left font-medium"', $html);
        $this->assertStringContainsString('+38 044 111 22 33</button>', $html);
        $this->assertStringContainsString('<a href="tel:+380441112233">Call</a>', $html);
    }

    /** A hint opens on hover as well; a panel without `hover` is click-only. */
    public function test_hover_is_asked_for_not_assumed(): void
    {
        $hint = Blade::render('<x-popover hover placement="top">Visa<x-slot:trigger>V</x-slot:trigger></x-popover>');
        $panel = Blade::render('<x-popover>Copy<x-slot:trigger>Phone</x-slot:trigger></x-popover>');

        $this->assertStringContainsString('data-popover-hover', $hint);
        $this->assertStringNotContainsString('data-popover-hover', $panel);
    }
}

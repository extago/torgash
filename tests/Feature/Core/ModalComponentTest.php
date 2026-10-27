<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The popup shell every module and block builds on.
 *
 * Two things are pinned, and both are about where a modal ends up rather than
 * how it looks: it must be openable by a stable id, and it must contain no
 * <form> of its own — daisyUI's own examples close a dialog with
 * `<form method="dialog">`, and a modal is very often rendered inside another
 * form (the product page's buy box is one). A nested form is dropped by the
 * parser while its inputs stay behind, which is the bug that once deleted
 * products.
 */
class ModalComponentTest extends TestCase
{
    public function test_the_trigger_opens_the_dialog_by_its_own_id(): void
    {
        $html = Blade::render('<x-modal name="delivery" title="Delivery" trigger="Open">Body</x-modal>');

        $this->assertStringContainsString('id="modal-delivery"', $html);
        $this->assertStringContainsString('data-open-dialog="modal-delivery"', $html);
        // A trigger inside somebody else's form must not submit it.
        $this->assertStringContainsString('type="button"', $html);
    }

    public function test_a_modal_contains_no_form_of_its_own(): void
    {
        $html = Blade::render('<x-modal title="T" trigger="Open">Body<x-slot:footer>F</x-slot:footer></x-modal>');

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('modal-backdrop', $html);
    }

    /**
     * The trigger is an attribute, and this is why.
     *
     * A named slot written BEFORE the default content leaves an output buffer
     * open — Blade's own behaviour, measured here rather than remembered, and
     * it would be the natural way to write a trigger. The footer slot, which
     * belongs after the content, is clean.
     */
    public function test_rendering_a_modal_closes_every_buffer_it_opens(): void
    {
        $level = ob_get_level();

        Blade::render('<x-modal title="T" trigger="Open">Body<x-slot:footer>F</x-slot:footer></x-modal>');

        $this->assertSame($level, ob_get_level());
    }

    /** Header, frame and footer are three separate decisions. */
    public function test_the_zones_are_optional_and_independent(): void
    {
        $bare = Blade::render('<x-modal :dismissible="false">Just text</x-modal>');

        $this->assertStringNotContainsString('border-b border-base-300', $bare);
        $this->assertStringNotContainsString('modal-backdrop', $bare);

        $framed = Blade::render('<x-modal title="T" icon="gem" framed>Boxed</x-modal>');

        $this->assertStringContainsString('rounded-box border border-base-300', $framed);
        // The icon is rendered through <x-icon>, so an unknown name would throw.
        $this->assertStringContainsString('<svg', $framed);
    }

    /** Two modals on one page must not answer to the same id. */
    public function test_two_unnamed_modals_get_different_ids(): void
    {
        $html = Blade::render('<x-modal>A</x-modal><x-modal>B</x-modal>');

        preg_match_all('/id="(modal-[^"]+)"/', $html, $matches);

        $this->assertCount(2, $matches[1]);
        $this->assertNotSame($matches[1][0], $matches[1][1]);
    }
}

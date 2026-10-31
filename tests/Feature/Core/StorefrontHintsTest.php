<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A storefront hint is core's <x-popover hover> (storefront.md, "One hint"):
 * daisyUI's tooltip is another look and says nothing on a touch screen.
 */
class StorefrontHintsTest extends TestCase
{
    public function test_no_storefront_view_uses_the_daisyui_tooltip(): void
    {
        $found = [];

        foreach ([base_path('modules'), base_path('themes'), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

                if (! str_ends_with($path, '.blade.php') || str_contains($path, '/admin/') || str_contains($path, '/mail/')) {
                    continue;
                }
                if (preg_match('/\bdata-tip=|class="[^"]*\btooltip\b/', $file->getContents())) {
                    $found[] = str_replace(str_replace(DIRECTORY_SEPARATOR, '/', base_path()).'/', '', $path);
                }
            }
        }

        $this->assertSame([], $found, 'A hint on the storefront is <x-popover hover>, not daisyUI\'s tooltip.');
    }
}

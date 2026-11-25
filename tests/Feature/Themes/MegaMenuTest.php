<?php

namespace Tests\Feature\Themes;

use Tests\TestCase;

/**
 * The default theme's catalogue menu: the departments on the left, the
 * hovered one's contents on the right.
 */
class MegaMenuTest extends TestCase
{
    protected function node(string $id, string $label, array $children = []): object
    {
        return (object) [
            'id' => $id, 'kind' => 'link', 'label' => $label, 'href' => '/'.$id,
            'icon' => null, 'image' => null, 'badge' => null, 'badgeStyle' => 'primary', 'highlight' => false,
            'newTab' => false, 'cssClass' => null, 'count' => null, 'active' => false, 'trail' => false,
            'showOn' => 'all', 'children' => $children,
        ];
    }

    /**
     * A department with nothing under it has no panel on the right: its name
     * there alone, with an arrow, read as a sub-item it does not have (a
     * live shop's "New arrivals", a single link).
     */
    public function test_a_department_without_children_has_no_panel(): void
    {
        $this->get('http://localhost/')->assertOk(); // the theme's views are in place

        $html = view('layouts.partials.mega-menu', ['megaItems' => collect([
            $this->node('phones', 'Phones', [$this->node('iphone', 'iPhone')]),
            $this->node('fresh', 'New arrivals'),
        ])])->render();

        $this->assertStringContainsString('x-show="megaActive === \'phones\'"', $html);
        $this->assertStringNotContainsString('x-show="megaActive === \'fresh\'"', $html);
    }

    /**
     * A long department name is shown whole by default; the theme can cut it
     * after one, two or three lines. Either way the row stands by its top, so
     * the icon sits beside the first line.
     */
    public function test_a_long_name_is_whole_unless_the_theme_cuts_it(): void
    {
        $this->get('http://localhost/')->assertOk();
        $items = collect([$this->node('watches', 'Smart watches, fitness bracelets and their straps', [$this->node('apple', 'Apple')])]);
        $name = fn (string $html) => preg_match('#<span class="flex-1 min-w-0 ([^"]+)"[^>]*>Smart watches#', $html, $m) ? $m[1] : null;

        $html = view('layouts.partials.mega-menu', ['megaItems' => $items])->render();
        $this->assertSame('break-words', $name($html));
        $this->assertStringContainsString('flex items-start gap-3', $html);

        $storefront = \App\Models\Storefront::query()->orderBy('id')->firstOrFail();
        foreach (['1' => 'truncate', '2' => 'line-clamp-2', '3' => 'line-clamp-3'] as $lines => $class) {
            app(\App\Core\Themes\ThemeSettings::class)->save($storefront, ['catalog_menu_name_lines' => $lines]);
            app(\App\Core\Themes\ThemeSettings::class)->flush();
            $this->assertSame($class, $name(view('layouts.partials.mega-menu', ['megaItems' => $items])->render()), "cut after $lines");
        }
    }
}

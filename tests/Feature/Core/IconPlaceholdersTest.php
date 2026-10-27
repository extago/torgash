<?php

namespace Tests\Feature\Core;

use App\Core\Content\IconPlaceholders;
use App\Core\Content\Placeholders;
use App\Core\Settings\SettingsManager;
use Tests\TestCase;

/**
 * `{icon_gem}` in admin-written HTML — for a list the shop keeps, not for the
 * whole pack.
 *
 * All 2035 lucide icons are vendored, and every one of them as a token would
 * make the placeholder picker a menu nobody can read. So the list is a setting:
 * adding an icon is typing its name, and a module can add its own through
 * `core.icon_tokens` without asking the shop.
 */
class IconPlaceholdersTest extends TestCase
{
    protected function refresh(): Placeholders
    {
        $placeholders = app(Placeholders::class);
        $placeholders->flush();
        app(IconPlaceholders::class)->register($placeholders);

        return $placeholders;
    }

    public function test_only_the_listed_icons_become_tokens(): void
    {
        app(SettingsManager::class)->set('core', 'icon_tokens', 'gem, truck');

        $tokens = array_keys($this->refresh()->all());

        $this->assertContains('icon_gem', $tokens);
        $this->assertContains('icon_truck', $tokens);
        // Vendored, but not on the shop's list — so not in the picker.
        $this->assertNotContains('icon_anchor', $tokens);
    }

    public function test_an_icon_that_does_not_exist_is_not_registered(): void
    {
        app(SettingsManager::class)->set('core', 'icon_tokens', 'gem, not-an-icon');

        $this->refresh();

        // Left standing in the text rather than resolving to nothing: a typo
        // has to be visible to whoever wrote it.
        $this->assertSame('{icon_not-an-icon}', expand_placeholders('{icon_not-an-icon}'));
        $this->assertStringContainsString('<svg', expand_placeholders('{icon_gem}'));
    }

    public function test_a_module_can_add_one_without_touching_the_setting(): void
    {
        app(SettingsManager::class)->set('core', 'icon_tokens', 'gem');
        hook_listen('core.icon_tokens', fn (array $names) => [...$names, 'package']);

        $tokens = array_keys($this->refresh()->all());

        $this->assertContains('icon_package', $tokens);
    }

    /** The markup is an inline svg, sized and hidden from screen readers. */
    public function test_the_token_renders_a_sized_svg(): void
    {
        app(SettingsManager::class)->set('core', 'icon_tokens', 'gem');
        $this->refresh();

        $html = expand_placeholders('{icon_gem}');

        $this->assertStringContainsString('h-4 w-4', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }
}

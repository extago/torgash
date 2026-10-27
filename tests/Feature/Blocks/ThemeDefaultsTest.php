<?php

namespace Tests\Feature\Blocks;

use App\Models\User;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ThemeDefaults;

/**
 * Getting a zone back to what the theme meant it to be.
 *
 * Until now this was a one-way street: a migration laid the theme's blocks out
 * once, guarded per zone, and never looked again. The guard is right — an
 * upgrade must not drop a block back into a zone somebody has arranged — but it
 * left an owner who took the header apart with no way back except reading the
 * theme file, and left a theme that adds `default_blocks` in a new version
 * unable to reach an installed shop at all.
 *
 * What is pinned here is mostly the difference between the two modes, and the
 * mark that makes the destructive one honest: without knowing whose brick is
 * whose, "replace" cannot warn about the afternoon it is about to delete.
 */
class ThemeDefaultsTest extends TestCase
{
    protected string $zone = 'storefront.header.after_search';

    protected function defaults(): ThemeDefaults
    {
        return app(ThemeDefaults::class);
    }

    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    /** The theme on disk furnishes this zone, and the installed shop has it. */
    public function test_the_theme_ships_content_for_a_zone_and_the_shop_has_it(): void
    {
        $found = $this->defaults()->zones();

        $this->assertArrayHasKey($this->zone, $found);
        $this->assertNotEmpty($found[$this->zone]['defaults']);
        $this->assertSame([], $found[$this->zone]['missing'], 'a freshly installed shop is already complete');
    }

    /** The owner takes the header apart, then wants it back. */
    public function test_a_dismantled_zone_can_be_restored(): void
    {
        Block::where('zone', $this->zone)->delete();

        $this->assertCount(1, $this->defaults()->zones()[$this->zone]['missing']);

        $added = $this->defaults()->add($this->zone);

        $this->assertSame(1, $added);
        $this->assertSame(1, Block::where('zone', $this->zone)->count());
        // Marked as the theme's, which is what makes a later "replace" able to
        // say whose each block is.
        $this->assertSame(ThemeDefaults::SOURCE, Block::where('zone', $this->zone)->value('source'));
    }

    /**
     * The safe mode is safe: what the owner arranged is not touched, not
     * reordered and not duplicated.
     */
    public function test_adding_leaves_the_owners_own_blocks_alone(): void
    {
        Block::where('zone', $this->zone)->delete();

        $mine = Block::create([
            'zone' => $this->zone,
            'type' => 'html',
            'title' => 'My own banner',
            'settings' => ['content' => ['en' => 'mine']],
            'sort' => 0,
            'enabled' => true,
        ]);

        $this->defaults()->add($this->zone);

        $mine->refresh();

        $this->assertSame('My own banner', $mine->title);
        $this->assertNull($mine->source, 'the owner’s block was claimed by the theme');
        $this->assertSame(2, Block::where('zone', $this->zone)->count());
        // Appended, never inserted above what was there.
        $this->assertTrue(
            Block::where('zone', $this->zone)->where('source', ThemeDefaults::SOURCE)->value('sort') > $mine->sort,
        );
    }

    /**
     * Matched by type AND title, so pressing "add missing" twice does not hand
     * the shop two copies of the theme's own block.
     */
    public function test_adding_twice_adds_nothing_the_second_time(): void
    {
        Block::where('zone', $this->zone)->delete();

        $this->assertSame(1, $this->defaults()->add($this->zone));
        $this->assertSame(0, $this->defaults()->add($this->zone));
        $this->assertSame(1, Block::where('zone', $this->zone)->count());
    }

    /**
     * A theme block the owner has EDITED still counts as present: they have it,
     * they changed it, and handing them a pristine second copy is not what
     * "add what is missing" says on the button.
     */
    public function test_an_edited_theme_block_is_not_added_again(): void
    {
        $block = Block::where('zone', $this->zone)->firstOrFail();
        $block->update(['settings' => ['content' => ['en' => 'edited by the owner']]]);

        $this->assertSame(0, $this->defaults()->add($this->zone));
        $this->assertSame(1, Block::where('zone', $this->zone)->count());
    }

    /** Replace means replace: the zone ends up as the theme ships it, in its order. */
    public function test_replacing_puts_the_zone_back_and_discards_everything_else(): void
    {
        Block::create([
            'zone' => $this->zone,
            'type' => 'html',
            'title' => 'My own banner',
            'settings' => [],
            'sort' => 5,
            'enabled' => true,
        ]);

        $result = $this->defaults()->replace($this->zone);

        $this->assertSame(2, $result['removed']);
        $this->assertSame(1, $result['added']);

        $left = Block::where('zone', $this->zone)->get();

        $this->assertCount(1, $left);
        $this->assertSame(ThemeDefaults::SOURCE, $left->first()->source);
        $this->assertSame(0, (int) $left->first()->sort);
    }

    // -------------------------------------------------------------- the screen

    public function test_the_screen_names_what_replacing_would_throw_away(): void
    {
        Block::create([
            'zone' => $this->zone,
            'type' => 'html',
            'title' => 'My own banner',
            'settings' => [],
            'sort' => 5,
            'enabled' => true,
        ]);

        $this->actingAs($this->admin())
            ->get('http://localhost/admin/blocks/theme-defaults')
            ->assertOk()
            // What is there, by name, marked as the owner's rather than the
            // theme's — the whole reason `source` exists.
            ->assertSee('My own banner')
            ->assertSee('yours')
            ->assertSee('Replace the zone');
    }

    public function test_the_buttons_do_what_the_service_does(): void
    {
        Block::where('zone', $this->zone)->delete();

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/blocks/theme-defaults/'.$this->zone.'/add')
            ->assertRedirect();

        $this->assertSame(1, Block::where('zone', $this->zone)->count());

        Block::create([
            'zone' => $this->zone, 'type' => 'html', 'title' => 'Mine', 'settings' => [], 'sort' => 9, 'enabled' => true,
        ]);

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/blocks/theme-defaults/'.$this->zone.'/replace')
            ->assertRedirect();

        $this->assertSame(1, Block::where('zone', $this->zone)->count());
        $this->assertSame(ThemeDefaults::SOURCE, Block::where('zone', $this->zone)->value('source'));
    }

    /** A zone nobody declared is not a zone, however the URL is typed. */
    public function test_an_unknown_zone_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post('http://localhost/admin/blocks/theme-defaults/made.up.zone/replace')
            ->assertNotFound();
    }

    /** Arranging the storefront is a permission, and this is arranging it. */
    public function test_it_is_behind_the_blocks_permission(): void
    {
        $nobody = User::create(['name' => 'Никто', 'email' => 'nobody@example.test', 'password' => 'secret123']);

        $this->actingAs($nobody)
            ->get('http://localhost/admin/blocks/theme-defaults')
            ->assertForbidden();
    }
}

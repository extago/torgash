<?php

namespace Tests\Feature\Blocks;

use App\Models\User;
use Tests\TestCase;
use Torgash\Blocks\Models\Block;

/**
 * The block editor still opens, and still offers the token list.
 *
 * Written when that list became a shared component (`<x-admin.placeholders>`,
 * PLAN §30.10): three screens now render the same markup, and the only thing
 * standing between "a token nobody can find" and a working picker is a view
 * that renders at all.
 */
class BlockFormPlaceholdersTest extends TestCase
{
    public function test_the_html_block_form_offers_the_placeholder_picker(): void
    {
        $block = Block::create([
            'zone' => 'storefront.header.after_search',
            'type' => 'html',
            'settings' => ['html' => '<p>{phone}</p>'],
            'enabled' => true,
            'sort' => 1,
            'rules_match' => 'all',
        ]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/blocks/'.$block->id.'/edit')
            ->assertOk()
            // The dialog, and a token in it that core itself registers.
            ->assertSee('placeholders-block')
            ->assertSee('{phone}');
    }
}

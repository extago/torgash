<?php

namespace Tests\Feature\Blocks;

use App\Models\User;
use Tests\TestCase;
use Torgash\Blocks\CssClasses;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\Section;
use Torgash\Blocks\ZoneRenderer;

/**
 * The band a block stands in (PLAN §51): the page's width, a background
 * across the screen, or edge to edge; a row of columns stands in its first
 * block's band; only known classes and a real #hex ever reach the page.
 */
class SectionTest extends TestCase
{
    protected string $zone = 'storefront.home.content';

    protected function setUp(): void
    {
        parent::setUp();

        Block::where('zone', $this->zone)->delete();
    }

    public function test_only_what_the_section_knows_is_kept(): void
    {
        $this->assertNull(Section::accept(['width' => 'page', 'background' => '', 'space' => 'none']), 'the plain band needs no column');
        $this->assertSame(['width' => 'band', 'background' => 'dark', 'colour' => null, 'space' => 'large', 'class' => null],
            Section::accept(['width' => 'band', 'background' => 'dark', 'colour' => '#123456', 'space' => 'large']));
        $this->assertSame(['width' => 'full', 'background' => 'colour', 'colour' => '#ffdd2d', 'space' => 'none', 'class' => null],
            Section::accept(['width' => 'full', 'background' => 'colour', 'colour' => '#FFDD2D']));
        $this->assertSame(['width' => 'full', 'background' => '', 'colour' => null, 'space' => 'none', 'class' => null],
            Section::accept(['width' => 'full', 'background' => 'colour', 'colour' => 'red; } body {']), 'a colour that is not a #hex is no background');
        $this->assertNull(Section::accept(['width' => 'everywhere', 'background' => 'bg-[url(x)]', 'space' => 'huge']));
    }

    public function test_a_block_and_a_row_stand_in_their_bands(): void
    {
        $alone = $this->html('Alone', 1, ['section' => ['width' => 'band', 'background' => 'dark', 'space' => 'medium']]);
        $this->html('Left', 2, ['row' => 'r', 'section' => ['width' => 'full', 'background' => 'colour', 'colour' => '#ffdd2d', 'space' => 'none']]);
        $this->html('Right', 3, ['row' => 'r', 'section' => ['width' => 'band', 'background' => 'dark']]);
        $this->html('Plain', 4);

        $out = app(ZoneRenderer::class)->render(Block::where('zone', $this->zone)->orderBy('sort')->get());

        $this->assertMatchesRegularExpression('#<section class="torgash-section torgash-section-band bg-neutral text-neutral-content py-8 md:py-10"><div class="torgash-section-inner"><div[^>]*><p>Alone#', $out);
        // The row: one band, the first column's.
        $this->assertSame(1, substr_count($out, 'torgash-section-full'));
        $this->assertMatchesRegularExpression('#torgash-section-full[^>]*style="background-color: \#ffdd2d"><div class="torgash-section-inner"><div class="torgash-block-row#', $out);
        $this->assertSame(2, substr_count($out, '<section class="torgash-section'), 'the plain block has no band');
        $this->assertNotNull($alone->fresh()->section);
    }

    public function test_the_zone_screen_sets_a_rows_section(): void
    {
        $left = $this->html('Left', 1, ['row' => 'r']);
        $this->html('Right', 2, ['row' => 'r']);
        $this->actingAs(User::role('admin')->firstOrFail());

        // The screen offers it per row, for the row's first block.
        $this->get('http://localhost/admin/blocks/zone/'.$this->zone)->assertOk()
            ->assertSee('modal-section-'.$left->id, false)
            ->assertSee(route('admin.blocks.section', $left), false);

        $this->patch('http://localhost/admin/blocks/'.$left->id.'/section', [
            'section' => ['width' => 'band', 'background' => 'muted', 'space' => 'small'],
        ])->assertRedirect();

        // jsonb sorts its keys: compared as a map, not in order.
        $this->assertEquals(['width' => 'band', 'background' => 'muted', 'colour' => null, 'space' => 'small', 'class' => null], $left->fresh()->section);
        $this->get('http://localhost/admin/blocks/zone/'.$this->zone)->assertSee(__('Background across the screen, content the page\'s width'));

        // Saving the block's own form leaves its section alone.
        $this->put('http://localhost/admin/blocks/'.$left->id, ['enabled' => '1', 'input_mode' => 'form', 'settings' => ['html' => '<p>Left</p>']])->assertRedirect();
        $this->assertNotNull($left->fresh()->section);
    }

    /**
     * The shop's own classes: on the band, on the block's outermost element
     * (merged into its class, or wrapping output that has no element first),
     * and only plain names — anything else is refused by the form.
     */
    public function test_a_block_and_its_band_carry_the_shops_classes(): void
    {
        $this->assertSame('promo -x _y', CssClasses::accept('promo, -x _y promo 9no "><script> a:b'));
        $this->assertSame(['9no', '"><script>', 'a:b'], CssClasses::rejected('promo 9no "><script> a:b'));

        $this->assertSame('<div x-show="n > 1" class="torgash-block home-hits">x</div>',
            CssClasses::onRoot('<div x-show="n > 1" class="torgash-block">x</div>', 'home-hits'));
        $this->assertSame('<!-- c --><nav :class="a" class="home-menu">x</nav>',
            CssClasses::onRoot('<!-- c --><nav :class="a">x</nav>', 'home-menu'));
        $this->assertSame('<div class="t">plain text</div>', CssClasses::onRoot('plain text', 't'));
        $this->assertSame('<div class="t"><style>a{}</style><p>x</p></div>', CssClasses::onRoot('<style>a{}</style><p>x</p>', 't'));

        $this->html('Named', 1, ['css_class' => 'home-hits', 'section' => ['class' => 'home-band']]);
        $out = app(ZoneRenderer::class)->render(Block::where('zone', $this->zone)->orderBy('sort')->get());

        // A band with only a class is a plain box: naming a row must not move it.
        $this->assertStringStartsWith('<div class="home-band"><div class="torgash-block torgash-block-html home-hits">', trim($out));

        $block = Block::where('zone', $this->zone)->firstOrFail();
        $this->actingAs(User::role('admin')->firstOrFail());
        $this->put('http://localhost/admin/blocks/'.$block->id, ['enabled' => '1', 'input_mode' => 'form', 'css_class' => 'ok bad"name',
            'settings' => ['html' => '<p>Named</p>']])->assertSessionHasErrors('css_class');
        $this->put('http://localhost/admin/blocks/'.$block->id, ['enabled' => '1', 'input_mode' => 'form', 'css_class' => 'a  b',
            'settings' => ['html' => '<p>Named</p>']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('a b', $block->fresh()->css_class);
    }

    protected function html(string $text, int $sort, array $extra = []): Block
    {
        return Block::create(['zone' => $this->zone, 'type' => 'html', 'title' => $text, 'enabled' => true, 'sort' => $sort,
            'settings' => ['html' => '<p>'.$text.'</p>'], 'rules_match' => 'all', ...$extra]);
    }
}

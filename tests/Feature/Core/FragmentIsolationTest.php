<?php

namespace Tests\Feature\Core;

use App\Core\Content\Fragment;
use App\Models\User;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Tests\TestCase;
use Torgash\Blocks\BlockTypes;
use Torgash\Blocks\Models\Block;
use Torgash\Blocks\ZoneRenderer;

/**
 * A part of a page that throws costs that part, not the page
 * (App\Core\Content\Fragment): the rest still prints, a customer sees no
 * trace of it, staff see a plate naming it, and — the part that is easy to
 * get wrong — the page's content and stacks survive Blade's flushState().
 */
class FragmentIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolation is what a live shop does; under APP_DEBUG the exception
        // is thrown on for the developer (pinned below).
        config(['app.debug' => false]);
        View::addNamespace('fragtest', base_path('tests/fixtures/views'));
    }

    public function test_a_listener_that_throws_loses_its_part_and_the_others_still_print(): void
    {
        hook_listen('test.fragment.zone', fn (string $html) => $html.'<b>FIRST</b>');
        hook_listen('test.fragment.zone', fn () => throw new RuntimeException('a module broke'));
        hook_listen('test.fragment.zone', fn (string $html) => $html.'<b>THIRD</b>');

        $out = hook_render('test.fragment.zone');

        $this->assertStringContainsString('<b>FIRST</b>', $out, 'what was drawn before the failure stays');
        $this->assertStringContainsString('<b>THIRD</b>', $out);
        $this->assertStringNotContainsString('data-fragment-failed', $out, 'a visitor sees nothing of it');
    }

    public function test_the_page_keeps_its_content_and_stacks_when_a_view_in_its_header_breaks(): void
    {
        hook_listen('test.fragment.zone', fn (string $html) => $html.view('fragtest::fragment.broken')->render());
        hook_listen('test.fragment.zone', fn (string $html) => $html.view('fragtest::fragment.fine')->render());

        $out = view('fragtest::fragment.page')->render();

        $this->assertStringContainsString('THE-CONTENT', $out, '@yield after the failure still finds the section');
        $this->assertStringContainsString('THE-SCRIPT', $out, '@stack after the failure still finds the push');
        $this->assertStringContainsString('FINE-PART', $out, 'the next listener still draws');
        $this->assertStringNotContainsString('HALF-PUSHED', $out, 'what the broken view pushed goes with it');
        $this->assertStringNotContainsString('half-drawn', $out, 'and so does what it had printed');
    }

    public function test_staff_see_a_plate_naming_what_failed(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail());

        hook_listen('test.fragment.zone', fn () => throw new RuntimeException('a module broke'));

        $out = hook_render('test.fragment.zone');

        $this->assertStringContainsString('data-fragment-failed', $out);
        $this->assertStringContainsString('test.fragment.zone', $out, 'the position is named');
        $this->assertStringContainsString('a module broke', $out, 'and the reason');
    }

    public function test_under_debug_the_error_is_thrown_on(): void
    {
        config(['app.debug' => true]);

        $this->expectException(RuntimeException::class);

        Fragment::isolate(fn () => throw new RuntimeException('for the developer'), 'probe');
    }

    public function test_the_failure_is_reported(): void
    {
        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->reportable(function (RuntimeException $e) use (&$reported) {
                $reported[] = $e->getMessage();

                return false;
            });

        Fragment::isolate(fn () => throw new RuntimeException('goes to the log'), 'probe');

        $this->assertSame(['goes to the log'], $reported);
    }

    public function test_one_broken_block_leaves_the_rest_of_its_zone(): void
    {
        BlockTypes::register('test_broken', ['label' => 'Broken', 'view' => 'fragtest::fragment.broken']);
        $zone = 'storefront.home.content';
        Block::where('zone', $zone)->delete();

        Block::create(['zone' => $zone, 'type' => 'html', 'title' => 'Before', 'enabled' => true, 'sort' => 1, 'settings' => ['html' => '<p>BEFORE-BLOCK</p>'], 'rules_match' => 'all']);
        $broken = Block::create(['zone' => $zone, 'type' => 'test_broken', 'title' => 'The broken one', 'enabled' => true, 'sort' => 2, 'settings' => [], 'rules_match' => 'all']);
        Block::create(['zone' => $zone, 'type' => 'html', 'title' => 'After', 'enabled' => true, 'sort' => 3, 'settings' => ['html' => '<p>AFTER-BLOCK</p>'], 'rules_match' => 'all']);

        $blocks = Block::where('zone', $zone)->orderBy('sort')->get();
        $out = app(ZoneRenderer::class)->render($blocks);

        $this->assertStringContainsString('BEFORE-BLOCK', $out);
        $this->assertStringContainsString('AFTER-BLOCK', $out);
        $this->assertStringNotContainsString('data-fragment-failed', $out);

        $this->actingAs(User::role('admin')->firstOrFail());
        $staff = app(ZoneRenderer::class)->render($blocks);

        $this->assertStringContainsString('The broken one', $staff, 'staff are told which block');
        $this->assertStringContainsString(route('admin.blocks.edit', $broken), $staff, 'and where to fix it');
    }
}

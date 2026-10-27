<?php

namespace Tests\Feature\Core;

use App\Core\Seo\RedirectManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The redirects screen in the admin's common shape, and the two facts it
 * learned to show: who wrote a row (by hand, on a slug change, from a file)
 * and whether anybody still arrives through it — counted only when a redirect
 * is actually SERVED.
 */
class RedirectsScreenTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function redirects(): RedirectManager
    {
        return app(RedirectManager::class);
    }

    protected function row(string $from): ?object
    {
        return DB::table('redirects')->where('from_path', $from)->first();
    }

    public function test_each_door_records_who_wrote_the_row(): void
    {
        $this->redirects()->put('/by-hand-x', '/catalog');
        $this->redirects()->recordChange('/product/old-x', '/product/new-x');
        $this->redirects()->import("/from-file-x,/catalog");

        $this->assertSame('manual', $this->row('/by-hand-x')->source);
        $this->assertSame('auto', $this->row('/product/old-x')->source);
        $this->assertSame('import', $this->row('/from-file-x')->source);
    }

    public function test_a_served_redirect_is_counted(): void
    {
        $this->redirects()->put('/counted-x', '/catalog');

        $this->get('http://localhost/counted-x')->assertStatus(301);
        $this->get('http://localhost/counted-x')->assertStatus(301);

        $row = $this->row('/counted-x');
        $this->assertSame(2, (int) $row->hits);
        $this->assertNotNull($row->last_hit_at);
    }

    public function test_tabs_search_and_the_unused_sort(): void
    {
        $this->redirects()->put('/zz-hand', '/catalog');
        $this->redirects()->import('/zz-file,/catalog');
        DB::table('redirects')->where('from_path', '/zz-file')->update(['hits' => 5]);

        $this->actingAs($this->admin)->get('http://localhost/admin/redirects?q=zz-&source=import')
            ->assertOk()->assertSee('/zz-file')->assertDontSee('/zz-hand');
        $this->get('http://localhost/admin/redirects?q=zz-&sort=unused')
            ->assertSee('/zz-hand')->assertDontSee('/zz-file');
    }

    public function test_a_row_is_corrected_in_place_and_a_new_old_address_replaces_the_typo(): void
    {
        $this->redirects()->recordChange('/typo-x', '/catalog');
        $id = $this->row('/typo-x')->id;

        $this->actingAs($this->admin)->put('http://localhost/admin/redirects/'.$id, [
            'from_path' => '/fixed-x', 'to_path' => '/catalog/laptops',
        ])->assertRedirect();

        $this->assertNull($this->row('/typo-x'));
        $this->assertSame('/catalog/laptops', $this->row('/fixed-x')->to_path);
        // Correcting an automatic row does not make it a hand-written one.
        $this->assertSame('auto', $this->row('/fixed-x')->source);
    }

    public function test_bulk_remove_and_export_in_the_shape_the_import_reads(): void
    {
        $this->redirects()->put('/bulk-a', '/catalog');
        $this->redirects()->put('/bulk-b', '/catalog');

        $csv = $this->actingAs($this->admin)->get('http://localhost/admin/redirects/export')->assertOk()->streamedContent();
        $this->assertStringStartsWith("from,to,source,hits,last_hit_at\n", $csv);
        $this->assertStringContainsString('/bulk-a,/catalog,manual,0,', $csv);

        $ids = DB::table('redirects')->whereIn('from_path', ['/bulk-a', '/bulk-b'])->pluck('id')->all();
        $this->post('http://localhost/admin/redirects/bulk', ['action' => 'delete', 'ids' => $ids])->assertRedirect();
        $this->assertSame(0, DB::table('redirects')->whereIn('from_path', ['/bulk-a', '/bulk-b'])->count());

        // What went out comes back in.
        DB::table('redirects')->delete();
        $this->assertSame(['imported' => 2, 'refused' => 0], $this->redirects()->import($csv));
    }
}

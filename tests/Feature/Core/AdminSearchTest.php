<?php

namespace Tests\Feature\Core;

use App\Core\Admin\AdminSearch;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The admin's "where is that set?" box: screens and settings found by the
 * words for them, in any language they are written in, with a link that lands
 * on the setting itself.
 */
class AdminSearchTest extends TestCase
{
    public function test_a_setting_is_found_by_its_label_and_links_to_its_own_anchor(): void
    {
        $this->actingAs($this->admin());

        $urls = array_column(app(AdminSearch::class)->search('availability status first'), 'url');

        $this->assertContains(route('admin.settings.edit', 'torgash.catalog').'#setting-out_of_stock_last', $urls);
    }

    /** A Russian word finds it whatever language the interface is in. */
    public function test_every_language_of_an_entry_is_searched(): void
    {
        $this->actingAs($this->admin());
        app()->setLocale('en');

        $results = app(AdminSearch::class)->search('предзаказ');
        $urls = array_column($results, 'url');

        // The screen, through its manifest keywords…
        $this->assertContains(url('/admin/catalog/availability-statuses'), $urls);
        // …and the status itself, through the catalogue's own entries.
        $this->assertContains(route('admin.catalog.availability-statuses.edit', 'preorder'), $urls);
        // Shown in the language the admin is reading.
        $this->assertContains('Availability statuses', array_column($results, 'title'));
    }

    public function test_the_screen_ranks_above_settings_that_only_mention_the_word(): void
    {
        $this->actingAs($this->admin());

        $first = app(AdminSearch::class)->search('availability statuses')[0] ?? null;

        $this->assertSame(url('/admin/catalog/availability-statuses'), $first['url'] ?? null);
    }

    public function test_htmx_gets_the_list_and_a_plain_request_gets_a_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('http://localhost/admin/search?q=currenc', ['HX-Request' => 'true'])
            ->assertOk()
            ->assertSee('data-admin-search-results', false)
            ->assertDontSee('<html', false);

        $this->actingAs($admin)->get('http://localhost/admin/search?q=currenc')
            ->assertOk()
            ->assertSee('<html', false)
            ->assertSee(route('admin.currencies.index'), false);
    }

    public function test_a_screen_the_person_may_not_open_is_not_offered(): void
    {
        $role = Role::findOrCreate('catalogue-only');
        $role->syncPermissions(['admin.access']);
        $manager = User::create(['name' => 'Cat', 'email' => 'cat@torgash.local', 'password' => 'secret123']);
        $manager->assignRole($role);

        $this->actingAs($manager);
        $this->assertNotContains(route('admin.administrators.index'), array_column(app(AdminSearch::class)->search('administrators'), 'url'));

        $this->actingAs($this->admin());
        $this->assertContains(route('admin.administrators.index'), array_column(app(AdminSearch::class)->search('administrators'), 'url'));
    }

    public function test_the_settings_screen_carries_the_anchor_the_search_links_to(): void
    {
        $this->actingAs($this->admin())->get('http://localhost/admin/settings/torgash.catalog')
            ->assertOk()
            ->assertSee('id="setting-out_of_stock_last"', false);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}

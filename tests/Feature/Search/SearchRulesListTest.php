<?php

namespace Tests\Feature\Search;

use App\Models\User;
use Tests\TestCase;
use Torgash\Search\Models\Synonym;

/**
 * The rule screens in the admin's common shape: a search over the rule's own
 * words, tabs by state, the switch in place, several at once — and the form
 * above the list opening by itself when the statistics send a query over.
 */
class SearchRulesListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function synonym(string $phrase, string $target, bool $enabled = true): Synonym
    {
        return Synonym::create(['phrases' => [$phrase], 'target' => $target, 'mode' => 'also', 'enabled' => $enabled]);
    }

    public function test_search_and_tabs_narrow_the_rules(): void
    {
        $this->synonym('zzmobila', 'smartphone');
        $this->synonym('zznout', 'laptop', false);

        $this->actingAs($this->admin)->get('http://localhost/admin/search-engine/rules/synonyms?q=zzmob')
            ->assertOk()->assertSee('zzmobila')->assertDontSee('zznout');
        $this->get('http://localhost/admin/search-engine/rules/synonyms?state=off&q=zz')
            ->assertSee('zznout')->assertDontSee('zzmobila');
    }

    public function test_status_in_place_and_in_bulk(): void
    {
        $one = $this->synonym('zzone', 'first');
        $two = $this->synonym('zztwo', 'second');

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/search-engine/rules/synonyms/{$one->id}/status", ['enabled' => 0], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('rule-status-synonyms-'.$one->id, false);
        $this->assertFalse((bool) $one->fresh()->enabled);

        $this->post('http://localhost/admin/search-engine/rules/synonyms/bulk', ['action' => 'delete', 'ids' => [$two->id]])->assertRedirect();
        $this->assertNull(Synonym::find($two->id));
        $this->assertNotNull(Synonym::find($one->id));
    }

    public function test_a_query_sent_from_the_statistics_opens_the_form_prefilled(): void
    {
        $this->actingAs($this->admin)->get('http://localhost/admin/search-engine/rules/synonyms?phrases=zzqueryx')
            ->assertOk()->assertSee('adding: true', false)->assertSee('zzqueryx');
        $this->get('http://localhost/admin/search-engine/rules/synonyms')
            ->assertSee('adding: false', false);
        $this->get('http://localhost/admin/search-engine?q=zzqueryx')->assertOk();
    }
}

<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\NovaPoshta\Models\PlaceMap;

/**
 * Finding one row among 29 420.
 *
 * The three slices are three different jobs — what still needs binding, what a
 * human already decided (the only rows a re-run will not touch, so the ones
 * worth re-reading), and everything, which is the only way to find a mapping
 * that went to the wrong namesake. Sorting has to agree with the column the
 * admin is looking at, which is the content locale, not a fixed language.
 */
class PlaceListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());

        // The list is read in Ukrainian and Russian, so both are languages
        // the shop writes in — the admin cannot be writing in one that is off.
        \App\Models\Language::query()->whereIn('code', ['uk', 'ru'])->update(['enabled' => true]);
    }

    public function test_the_default_slice_is_the_work_left_to_do(): void
    {
        $bound = $this->place('Бабинці', 'Бабинцы');
        $this->bind($bound, 'name_area');
        $loose = $this->place('Адамівка', 'Адамовка');

        $this->visit('http://localhost/admin/nova-poshta')
            ->assertOk()
            ->assertSee('Адамовка')
            ->assertDontSee('Бабинцы');
        $this->assertNotNull($loose->id);
    }

    public function test_bound_by_hand_is_its_own_slice(): void
    {
        $byHand = $this->place('Пʼятихатки', 'Пятихатки');
        $automatic = $this->place('Бабинці', 'Бабинцы');
        $this->bind($byHand, 'manual');
        $this->bind($automatic, 'coords');

        $this->visit('http://localhost/admin/nova-poshta?show=manual')
            ->assertOk()
            ->assertSee('Пятихатки')
            ->assertDontSee('Бабинцы');
    }

    public function test_it_sorts_by_the_name_actually_shown_not_by_a_fixed_language(): void
    {
        // Only the Ukrainian spellings are in alphabetical order; the Russian
        // ones are deliberately not, so a test passing by accident is unlikely.
        $this->place('Абрикосівка', 'Ялинка');
        $this->place('Бабинці', 'Абрикос');

        $this->visit('http://localhost/admin/nova-poshta?show=all&sort=place&dir=asc')
            ->assertOk()
            ->assertSeeInOrder(['Абрикос', 'Ялинка']);

        $this->visit('http://localhost/admin/nova-poshta?show=all&sort=place&dir=asc', 'uk')
            ->assertOk()
            ->assertSeeInOrder(['Абрикосівка', 'Бабинці']);
    }

    public function test_every_sortable_column_actually_runs(): void
    {
        // Sorting by region JOINS the states table, which has a `country`
        // column of its own — the unqualified filter beside it turned into
        // "ambiguous column" and a 500, on that one column only. Hence a test
        // that walks all of them rather than the two that happened to work.
        $this->place('Бабинці', 'Бабинцы');

        foreach (['place', 'region', 'population'] as $column) {
            foreach (['asc', 'desc'] as $direction) {
                $this->visit("http://localhost/admin/nova-poshta?show=all&sort={$column}&dir={$direction}")
                    ->assertOk()
                    ->assertSee('Бабинцы');
            }
        }
    }

    public function test_sorting_by_region_searches_and_filters_at_the_same_time(): void
    {
        $this->place('Бабинці', 'Бабинцы');

        $this->visit('http://localhost/admin/nova-poshta?sort=region&dir=asc&q=Бабин')
            ->assertOk()
            ->assertSee('Бабинцы');
    }

    public function test_population_sorts_biggest_first_by_default_and_flips_on_request(): void
    {
        $this->place('Мале', 'Малое', population: 120);
        $this->place('Велике', 'Большое', population: 90_000);

        $this->visit('http://localhost/admin/nova-poshta?show=all')
            ->assertOk()
            ->assertSeeInOrder(['Большое', 'Малое']);

        $this->visit('http://localhost/admin/nova-poshta?show=all&sort=population&dir=asc')
            ->assertOk()
            ->assertSeeInOrder(['Малое', 'Большое']);
    }

    public function test_a_made_up_sort_column_does_not_reach_the_database(): void
    {
        // The direction and the column are interpolated into raw SQL, so the
        // whitelist is the only thing between this and an injection.
        $this->visit('http://localhost/admin/nova-poshta?show=all&sort=id;drop+table+users&dir=or+1=1')
            ->assertOk();
    }

    /** The list renders the CONTENT locale, so a test about it has to say which. */
    protected function visit(string $url, string $locale = 'ru')
    {
        return $this->withSession(['admin_content_locale' => $locale])->get($url);
    }

    protected function place(string $uk, string $ru, int $population = 1000): Place
    {
        static $n = 0;
        $state = State::firstOrCreate(
            ['country' => 'UA', 'code' => 'NP'],
            ['name' => ['uk' => 'Тестова область', 'ru' => 'Тестовая область'], 'enabled' => true],
        );

        return Place::create([
            'country' => 'UA',
            'state_id' => $state->id,
            'name' => ['uk' => $uk, 'ru' => $ru, 'en' => 'Place '.(++$n)],
            'type' => 'settlement',
            'population' => $population,
            'latitude' => 50.0,
            'longitude' => 30.0,
            'enabled' => true,
        ]);
    }

    protected function bind(Place $place, string $matchedBy): void
    {
        DB::table('m_np_settlements')->insertOrIgnore([
            'ref' => str_pad('np-'.$place->id, 36, '0'),
            'kind' => 'settlement',
            'name_uk' => 'Пункт',
            'name_ru' => 'Пункт',
            'area' => 'Тестова',
            'has_warehouse' => true,
            'synced_at' => now(),
        ]);

        PlaceMap::create([
            'place_id' => $place->id,
            'settlement_ref' => str_pad('np-'.$place->id, 36, '0'),
            'matched_by' => $matchedBy,
            'confidence' => 100,
            'checked_at' => now(),
        ]);
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'np-list@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'np-list@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}

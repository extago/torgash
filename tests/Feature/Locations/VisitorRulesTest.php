<?php

namespace Tests\Feature\Locations;

use App\Core\Display\DisplayRules;
use App\Core\Display\RuleKinds;
use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\Locations\Places;

/**
 * "Only to visitors from…" (PLAN §51): display rules about the town the
 * visitor chose — its town, region, country — and about not having chosen
 * one. Nothing is guessed: no town chosen is no town.
 */
class VisitorRulesTest extends TestCase
{
    protected Place $kyiv;

    protected Place $lviv;

    protected function setUp(): void
    {
        parent::setUp();

        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);
        $kyivRegion = State::create(['country' => 'UA', 'code' => '30', 'name' => ['en' => 'Kyiv region'], 'enabled' => true]);
        $lvivRegion = State::create(['country' => 'UA', 'code' => '46', 'name' => ['en' => 'Lviv region'], 'enabled' => true]);
        $this->kyiv = Place::create(['country' => 'UA', 'state_id' => $kyivRegion->id, 'name' => ['en' => 'Kyiv'], 'geonameid' => 703448, 'population' => 3_000_000, 'source' => 'import']);
        $this->lviv = Place::create(['country' => 'UA', 'state_id' => $lvivRegion->id, 'name' => ['en' => 'Lviv'], 'geonameid' => 702550, 'population' => 700_000, 'source' => 'import']);
    }

    public function test_a_rule_asks_the_town_the_visitor_chose(): void
    {
        $rules = app(DisplayRules::class);
        $inKyiv = [['kind' => 'visitor_town', 'operator' => 'in', 'value' => [(string) $this->kyiv->id]]];
        $notKyiv = [['kind' => 'visitor_town', 'operator' => 'not_in', 'value' => [(string) $this->kyiv->id]]];
        $unknown = [['kind' => 'visitor_town_chosen', 'operator' => 'no']];

        // Nobody has said where they are: not in Kyiv, "not Kyiv", and the town is not known.
        $this->assertFalse($rules->passes($inKyiv));
        $this->assertTrue($rules->passes($notKyiv));
        $this->assertTrue($rules->passes($unknown));

        $this->choose($this->kyiv);
        $this->assertTrue($rules->passes($inKyiv));
        $this->assertFalse($rules->passes($notKyiv));
        $this->assertFalse($rules->passes($unknown));
        $this->assertTrue($rules->passes([['kind' => 'visitor_region', 'operator' => 'in', 'value' => [(string) $this->kyiv->state_id]]]));
        $this->assertTrue($rules->passes([['kind' => 'visitor_country', 'operator' => 'in', 'value' => ['UA']]]));

        $this->choose($this->lviv);
        $this->assertFalse($rules->passes($inKyiv));
        $this->assertFalse($rules->passes([['kind' => 'visitor_region', 'operator' => 'in', 'value' => [(string) $this->kyiv->state_id]]]));
    }

    public function test_towns_are_searched_and_named_never_listed(): void
    {
        $this->assertSame('search', RuleKinds::find('visitor_town')['value']);
        $this->assertSame([(string) $this->kyiv->id => $this->kyiv->label()], RuleKinds::labels('visitor_town', [(string) $this->kyiv->id]));
        $this->assertStringContainsString('Kyiv', app(DisplayRules::class)->describe(['kind' => 'visitor_town', 'operator' => 'in', 'value' => [(string) $this->kyiv->id]]));
        $this->assertSame([['kind' => 'visitor_town', 'operator' => 'in', 'value' => ['5', '7']]],
            app(DisplayRules::class)->normalise([['kind' => 'visitor_town', 'operator' => 'in', 'value' => ['5', '', '7', '5']]]));
    }

    protected function choose(Place $place): void
    {
        session(['checkout_location' => ['place_id' => $place->id, 'postal' => null, 'town' => null, 'country' => 'UA']]);
        app(Places::class)->flush();
    }
}

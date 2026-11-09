<?php

namespace Tests\Feature\Locations;

use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;

/**
 * What a typed word finds. Two letters match the start of a word and nothing
 * else: from the middle of a word they gave a trigram index nothing to look
 * for (15 s on a live shop, at every key press) and never named a town anybody
 * meant. Three letters on match any part of any name.
 */
class PlaceSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);

        foreach ([['Kyiv', 3_000_000], ['Bila Kyrnytsia', 900], ['Lykyno', 400], ['Днепр', 900_000]] as $i => [$name, $population]) {
            Place::create(['country' => 'UA', 'name' => ['en' => $name], 'type' => 'city', 'geonameid' => 990_000 + $i, 'population' => $population, 'source' => 'import']);
        }
    }

    /** @return list<string> */
    protected function found(string $term): array
    {
        return Place::query()->search($term)->get()->map(fn (Place $place) => $place->name['en'])->all();
    }

    public function test_two_letters_find_the_start_of_any_word_biggest_first(): void
    {
        $this->assertSame(['Kyiv', 'Bila Kyrnytsia'], $this->found('Ky'));
    }

    public function test_two_letters_do_not_find_the_middle_of_a_word(): void
    {
        $this->assertSame([], $this->found('yk'));
    }

    public function test_three_letters_find_any_part_of_a_name(): void
    {
        $this->assertSame(['Lykyno'], $this->found('yky'));
    }

    public function test_cyrillic_folds_case_either_way(): void
    {
        $this->assertSame(['Днепр'], $this->found('дн'));
        $this->assertSame(['Днепр'], $this->found('ДНЕ'));
    }

    public function test_what_like_treats_as_special_is_only_a_letter_here(): void
    {
        $this->assertSame([], $this->found('K%'));
        $this->assertSame([], $this->found('K_'));
        $this->assertSame([], $this->found('K\\'));
        $this->assertSame([], $this->found('K\\y'));
        $this->assertSame([], $this->found('Ky%v'));
    }

    public function test_an_empty_word_finds_nothing(): void
    {
        $this->assertSame([], $this->found('   '));
    }
}

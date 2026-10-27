<?php

namespace Tests\Feature\Cdek;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\Cdek\Api\ApiException;
use Torgash\Cdek\Api\Client;

/**
 * Every sync and every live rate goes through this. Pinned: the token is
 * asked for once and reused, a revoked token is replaced once (never in a
 * loop), and both of CDEK's error shapes become an exception rather than an
 * empty answer.
 */
class ApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_the_token_is_issued_once_and_reused(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'T1', 'expires_in' => 3600]),
            '*/location/cities*' => Http::response([['code' => 44, 'city' => 'Москва']]),
        ]);

        $client = new Client('acc', 'sec', test: false);
        $client->get('location/cities', ['country_codes' => 'RU']);
        $client->get('location/cities', ['country_codes' => 'RU']);

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'oauth/token')
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'acc'
            && str_starts_with($request->url(), Client::LIVE));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'location/cities')
            && $request->hasHeader('Authorization', 'Bearer T1'));
    }

    public function test_the_test_environment_falls_back_to_cdeks_published_account(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
            '*' => Http::response([]),
        ]);

        (new Client('', '', test: true))->get('deliverypoints', ['city_code' => 44]);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), Client::TEST)
            && str_ends_with($request->url(), 'oauth/token')
            && $request['client_id'] === Client::TEST_ACCOUNT);
    }

    public function test_a_revoked_token_is_replaced_once(): void
    {
        Http::fake([
            '*/oauth/token' => Http::sequence()
                ->push(['access_token' => 'OLD', 'expires_in' => 3600])
                ->push(['access_token' => 'NEW', 'expires_in' => 3600]),
            '*/deliverypoints*' => Http::sequence()
                ->push(['requests' => [['errors' => [['code' => 'v2_token_expired', 'message' => 'expired']]]]], 401)
                ->push([['code' => 'MSK1']]),
        ]);

        $rows = (new Client('acc', 'sec', test: false))->get('deliverypoints');

        $this->assertSame([['code' => 'MSK1']], $rows);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'deliverypoints') && $request->hasHeader('Authorization', 'Bearer NEW'));
    }

    public function test_a_calculator_refusal_with_status_200_is_still_a_refusal(): void
    {
        // Measured: an unknown city comes back as {"errors": [...]} and nothing
        // else. Read as data, that is a price of nothing — silently "free".
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
            '*/calculator/tariff' => Http::response(['errors' => [[
                'code' => 'v2_recipient_location_not_recognized',
                'message' => 'Recipient location is not recognized',
            ]]]),
        ]);

        try {
            (new Client('acc', 'sec', test: false))->post('calculator/tariff', []);
            $this->fail('a refusal should throw');
        } catch (ApiException $e) {
            $this->assertStringContainsString('not recognized', $e->getMessage());
            $this->assertSame(['v2_recipient_location_not_recognized'], $e->codes);
            $this->assertFalse($e->transient);
        }
    }

    public function test_pages_walk_until_a_short_page_and_retry_a_flaky_one(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
            '*/location/cities*' => Http::sequence()
                ->push([['code' => 1], ['code' => 2]])
                // Measured on the real listing: one page in thirty came back
                // as an empty body once and fine the second time.
                ->push('', 502)
                ->push([['code' => 3]]),
        ]);

        $rows = iterator_to_array(
            (new Client('acc', 'sec', test: false))->withoutRetryPause()->pages('location/cities', [], 2),
            preserve_keys: false,
        );

        $this->assertSame([1, 2, 3], array_column($rows, 'code'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'page=1') && str_contains($request->url(), 'size=2'));
    }

    public function test_no_keys_is_an_exception_not_a_request(): void
    {
        Http::fake();

        $this->expectException(ApiException::class);

        try {
            (new Client('', '', test: false))->get('location/cities');
        } finally {
            Http::assertNothingSent();
        }
    }
}

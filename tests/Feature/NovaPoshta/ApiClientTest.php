<?php

namespace Tests\Feature\NovaPoshta;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Torgash\NovaPoshta\Api\ApiException;
use Torgash\NovaPoshta\Api\Client;

/**
 * The one endpoint every sync and every live rate goes through. Pinned:
 * the request shape Nova Poshta expects, and that a refusal becomes an
 * exception rather than a silent empty result.
 */
class ApiClientTest extends TestCase
{
    public function test_a_call_sends_the_shape_nova_poshta_expects(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [['Ref' => 'r1']]])]);

        $rows = (new Client('KEY-123'))->call('Address', 'getCities', ['Foo' => 'bar']);

        $this->assertSame([['Ref' => 'r1']], $rows);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->url() === Client::URL
                && $body['apiKey'] === 'KEY-123'
                && $body['modelName'] === 'Address'
                && $body['calledMethod'] === 'getCities'
                && $body['methodProperties'] === ['Foo' => 'bar'];
        });
    }

    public function test_the_endpoint_keeps_its_trailing_slash(): void
    {
        // Measured against the live API: without the slash it answers 301 to
        // the same URL with one, the redirect turns the POST into a GET, the
        // body is dropped, and EVERY call comes back "Data is invalid" — with
        // a good key, a bad key or no key, which is what made it look like a
        // key problem for a day.
        $this->assertStringEndsWith('/json/', Client::URL);
    }

    public function test_a_refusal_becomes_an_exception_carrying_the_codes(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'errors' => ['API key expired'],
            'errorCodes' => ['20000100'],
        ])]);

        try {
            (new Client('DEAD'))->call('Address', 'getWarehouses');
            $this->fail('a refused call should throw');
        } catch (ApiException $e) {
            $this->assertStringContainsString('API key expired', $e->getMessage());
            $this->assertSame(['20000100'], $e->codes);
        }
    }

    public function test_an_http_failure_is_an_exception_too(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $this->expectException(ApiException::class);
        (new Client('KEY'))->call('Address', 'getWarehouses');
    }

    public function test_pages_keeps_asking_until_a_short_page(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['success' => true, 'data' => [['Ref' => 'a'], ['Ref' => 'b']]])
            ->push(['success' => true, 'data' => [['Ref' => 'c']]])]);

        $rows = iterator_to_array(
            (new Client('KEY'))->pages('AddressGeneral', 'getSettlements', [], pageSize: 2),
            preserve_keys: false,
        );

        $this->assertSame([['Ref' => 'a'], ['Ref' => 'b'], ['Ref' => 'c']], $rows);

        // Page 1 and page 2 asked for; a third is not, because page 2 was short.
        Http::assertSentCount(2);
        // Page travels as a STRING: the live API answers "Page is invalid
        // format" to a JSON number (measured, 2026-09-01).
        Http::assertSent(fn ($request) => json_decode($request->body(), true)['methodProperties']['Page'] === '2');
    }

    public function test_a_paced_client_waits_between_calls_and_a_plain_one_does_not(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

        // Measured against the live API: flat out, a key gets ten calls before
        // "To many requests"; 1.5 s apart it ran forty for forty. So bulk work
        // paces itself — and a checkout quote, which makes one or two calls,
        // must never pay for that.
        $plain = new Client('KEY');
        $started = microtime(true);
        $plain->call('Address', 'getWarehouses');
        $plain->call('Address', 'getWarehouses');
        $this->assertLessThan(0.1, microtime(true) - $started);

        $paced = (new Client('KEY'))->paced(0.2);
        $started = microtime(true);
        $paced->call('Address', 'getWarehouses');
        $paced->call('Address', 'getWarehouses');
        // Not `>= 0.2`: usleep lands a hair under its argument (measured
        // 0.19973), and this is asserting that it waited, not that a sleep is
        // accurate to the microsecond.
        $this->assertGreaterThan(0.15, microtime(true) - $started);
    }
}

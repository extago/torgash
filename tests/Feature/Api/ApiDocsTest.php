<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Tests\TestCase;
use Torgash\Api\ApiResources;
use Torgash\Api\OpenApi;

/**
 * The API describing itself.
 *
 * The claim being pinned is that the description is DERIVED: a module that
 * registers a resource is documented by the act of registering it, and a
 * document written from the registry cannot say the shop offers a filter the
 * code does not have. So the tests mostly register a throwaway resource and
 * then look for it — if that stops working, someone has started keeping the
 * reference by hand, which is the failure this design exists to prevent.
 */
class ApiDocsTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiResources::forget('admin.widgets');
        ApiResources::forget('shop.widgets');

        parent::tearDown();
    }

    protected function document(): array
    {
        return app(OpenApi::class)->document();
    }

    /** The description must be readable before you have a token: a client generating a stub does not have one yet. */
    public function test_the_document_is_public_and_is_openapi(): void
    {
        $response = $this->getJson('http://localhost/api/v2/openapi.json')->assertOk();

        $this->assertSame('3.1.0', $response->json('openapi'));
        $this->assertNotEmpty($response->json('info.title'));
        $this->assertSame('http', $response->json('components.securitySchemes.bearerAuth.type'));
    }

    /** Registering a resource is what documents it. Nothing else was edited here. */
    public function test_a_module_documents_itself_by_registering(): void
    {
        ApiResources::register('admin.widgets', [
            'label' => ['en' => 'Widgets'],
            'description' => ['en' => 'The things this shop sells.'],
            'query' => fn () => \Torgash\Catalog\Models\Product::query(),
            'lookup' => ['sku'],
            'sortable' => ['price'],
            'filters' => ['colour' => [
                'description' => ['en' => 'Only widgets of one colour.'],
                'apply' => fn ($q, $v) => $q,
            ]],
            'schema' => ['sku' => 'string', 'price' => ['type' => 'integer', 'description' => 'Minor units.']],
        ]);

        $document = $this->document();
        $index = $document['paths']['/api/v2/admin/widgets']['get'];

        $this->assertContains('Admin · Widgets', $index['tags']);
        // The filter, with the sentence the module wrote beside it.
        $this->assertContains('Only widgets of one colour.', array_column($index['parameters'], 'description'));
        // The sortable column, as the parameter a caller actually sends.
        $this->assertContains('order[price]', array_column($index['parameters'], 'name'));
        // The declared shape, reachable from the response.
        $this->assertSame(
            ['sku', 'price'],
            array_keys($document['components']['schemas']['AdminWidgets']['properties']),
        );
        $this->assertSame('Minor units.', $document['components']['schemas']['AdminWidgets']['properties']['price']['description']);
    }

    /** A read-only resource has no PATCH in the document, because it has none in the router either. */
    public function test_read_only_is_read_only_in_the_document(): void
    {
        ApiResources::register('admin.widgets', [
            'query' => fn () => \Torgash\Catalog\Models\Product::query(),
        ]);

        $paths = $this->document()['paths'];

        $this->assertArrayHasKey('get', $paths['/api/v2/admin/widgets/{key}']);
        $this->assertArrayNotHasKey('patch', $paths['/api/v2/admin/widgets/{key}']);
    }

    /** A named transition is its own path — that is the difference it exists to make visible. */
    public function test_an_action_is_documented_as_its_own_endpoint(): void
    {
        $operation = $this->document()['paths']['/api/v2/admin/orders/{key}/actions/status']['post'];

        $this->assertNotEmpty($operation['description']);
        $this->assertArrayHasKey('status', $operation['requestBody']['content']['application/json']['schema']['properties']);
    }

    /** Which half needs a token is stated per operation, not left to be inferred. */
    public function test_the_shop_half_says_out_loud_that_it_needs_nothing(): void
    {
        $paths = $this->document()['paths'];

        $this->assertSame([], $paths['/api/v2/shop/products']['get']['security']);
        $this->assertSame([['bearerAuth' => []]], $paths['/api/v2/admin/products']['get']['security']);
        // ...and the shop half is read-only, so nothing writes there.
        $this->assertArrayNotHasKey('patch', $paths['/api/v2/shop/products/{key}']);
    }

    /** The cart and the checkout are not resources, and would be missing if nobody had written them. */
    public function test_the_endpoints_that_are_not_resources_are_described_too(): void
    {
        $paths = $this->document()['paths'];

        foreach ([
            '/api/v2/shop/carts' => 'post',
            '/api/v2/shop/carts/{token}/items' => 'patch',
            '/api/v2/shop/carts/{token}/checkout' => 'patch',
            '/api/v2/shop/carts/{token}/checkout/complete' => 'post',
        ] as $path => $method) {
            $this->assertArrayHasKey($method, $paths[$path], $path);
        }
    }

    /** A module may add a path of its own — a gateway's callback, say. */
    public function test_a_module_can_add_a_path_through_the_hook(): void
    {
        hook_listen('api.openapi.paths', fn ($paths) => $paths + ['/pay/callback' => ['post' => ['summary' => 'Gateway callback']]]);

        $this->assertArrayHasKey('/pay/callback', $this->document()['paths']);
    }

    /** The page is the shop's own tool — it fires real calls, so it lives behind the same gate the tokens do. */
    public function test_the_reference_page_is_for_staff_only(): void
    {
        $this->get('http://localhost/admin/api/docs')->assertRedirect();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/api/docs')
            ->assertOk()
            ->assertSee('/api/v2/shop/carts/{token}/checkout')
            ->assertSee('openapi.json');
    }

    /** The prefilled body is the declared shape, not a hand-kept example that goes stale. */
    public function test_a_sample_body_is_built_from_the_shape(): void
    {
        $sample = app(OpenApi::class)->sample([
            'type' => 'object',
            'properties' => [
                'quantity' => ['type' => 'integer'],
                'sku' => ['type' => 'string', 'example' => 'ST-2002'],
                'status' => ['type' => 'string', 'enum' => ['active', 'hidden']],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
            ],
        ]);

        $this->assertSame(
            ['quantity' => 0, 'sku' => 'ST-2002', 'status' => 'active', 'lines' => [['id' => 0]]],
            $sample,
        );
    }
}

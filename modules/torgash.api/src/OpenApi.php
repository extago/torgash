<?php

namespace Torgash\Api;

/**
 * The API describing itself.
 *
 * Written from the REGISTRY rather than by hand, and that is the whole design:
 * a module that registers a resource is documented by the same act, in the same
 * place, and cannot drift from what the code actually serves. A hand-kept
 * OpenAPI file is a second source of truth about the first, and it is wrong
 * within a month — usually about the filter somebody added on a Friday.
 *
 * We do NOT introspect the controllers (Scramble and friends): there is exactly
 * one controller here and it serves every resource, so a reader of its signature
 * would learn `{resource}` and nothing else. Everything worth documenting —
 * which filters exist, what may be sorted on, which keys address a row, what may
 * be written, which named transitions there are — is already declared, because
 * the runtime needs it too.
 *
 * What a module cannot derive is prose: what a field MEANS. So a registration
 * may carry `description` and a `schema`, and where it does not, this says
 * nothing rather than inventing something.
 *
 * The endpoints that are not resources (the cart, the checkout conversation)
 * are described here explicitly and extended through the `api.openapi.paths`
 * hook, so a gateway module can document its own callback.
 */
class OpenApi
{
    /** @return array<string, mixed> */
    public function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (current_storefront()?->name ?: config('app.name')).' API',
                'version' => '2.0.0',
                'description' => $this->preamble(),
            ],
            'servers' => [['url' => url('/')]],
            'tags' => $this->tags(),
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => __('A token from Admin → API. It names a user, and every permission check is the admin panel’s own.'),
                    ],
                ],
                'parameters' => $this->contextParameters(),
                'schemas' => $this->schemas(),
            ],
            'paths' => hook_filter('api.openapi.paths', array_merge($this->resourcePaths(), $this->cartPaths())),
        ];
    }

    protected function preamble(): string
    {
        return implode("\n\n", [
            __('Two halves. `/api/v2/admin/…` answers with everything the shop knows and needs a bearer token; `/api/v2/shop/…` is public, exactly as the storefront’s own pages are, and only GET is routed for its resources.'),
            __('Every amount is an integer in **minor units** — 1999 is 19.99 — and the currency is always named beside it. A price is two facts, and sending the number alone is how an exchange puts one currency into another’s column.'),
            __('A row is addressed by our id **or** by whatever key your own system knows it as, so an integration never has to store our autoincrements.'),
            __('Answers are enveloped: a single row as `{"data": …}`, a list as `{"data": [...], "meta": {...}}`, a refusal as `{"message": …}` with `errors` when fields are at fault.'),
        ]);
    }

    // ----------------------------------------------------------------- pieces

    /** @return array<int, array<string, mixed>> */
    protected function tags(): array
    {
        $tags = [];

        foreach (ApiResources::all() as $name => $definition) {
            if (! $definition['query']) {
                continue;
            }

            $tags[] = [
                'name' => $this->tag($name, $definition),
                'description' => $this->text($definition['description'] ?? null),
            ];
        }

        $tags[] = ['name' => __('Storefront').' · '.__('Cart'), 'description' => __('A cart of one’s own, addressed by a token. The token IS the authorisation.')];
        $tags[] = ['name' => __('Storefront').' · '.__('Checkout'), 'description' => __('Checkout as a conversation with that cart: answer a step, read what is now on offer, complete.')];

        return $tags;
    }

    /**
     * The two headers every call may carry. Declared once and referenced, so
     * they are on every operation without being retyped onto every operation.
     *
     * @return array<string, mixed>
     */
    protected function contextParameters(): array
    {
        return [
            'Storefront' => [
                'name' => 'X-Storefront',
                'in' => 'header',
                'required' => false,
                'description' => __('Which storefront this call is about — its id or its code. The default storefront otherwise.'),
                'schema' => ['type' => 'string'],
            ],
            'Language' => [
                'name' => 'Accept-Language',
                'in' => 'header',
                'required' => false,
                'description' => __('Translated fields and prices come back in this language.'),
                'schema' => ['type' => 'string', 'example' => 'ru'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function schemas(): array
    {
        $schemas = [
            'Error' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'description' => __('Present when named fields are at fault: the field name, and what is wrong with it.'),
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ],
            'Meta' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer'],
                    'itemsPerPage' => ['type' => 'integer'],
                    'total' => ['type' => 'integer', 'description' => __('Rows matching the query, not rows on this page.')],
                    'pages' => ['type' => 'integer'],
                ],
            ],
        ];

        foreach (ApiResources::all() as $name => $definition) {
            if (! $definition['query']) {
                continue;
            }

            $schemas[$this->schemaName($name)] = $this->object($definition['schema'] ?? [], $this->text($definition['description'] ?? null));
        }

        return $schemas + $this->cartSchemas();
    }

    /**
     * A declared shape turned into JSON Schema.
     *
     * The declaration is deliberately terser than JSON Schema — `'price' =>
     * 'integer'`, or an array with a sentence — because a shape nobody wants to
     * type is a shape nobody keeps up to date.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function object(array $fields, ?string $description = null): array
    {
        $schema = array_filter([
            'type' => 'object',
            'description' => $description,
        ]);

        if ($fields === []) {
            // Honest about not knowing: a resource whose owner declared no shape
            // gets an open object, never an invented list of fields.
            $schema['additionalProperties'] = true;

            return $schema;
        }

        foreach ($fields as $field => $spec) {
            $schema['properties'][$field] = $this->property($spec);
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    protected function property(array|string $spec): array
    {
        $spec = is_string($spec) ? ['type' => $spec] : $spec;
        $types = explode('|', (string) ($spec['type'] ?? 'string'));

        $property = ['type' => count($types) === 1 ? $types[0] : $types];

        foreach (['description', 'example', 'enum', 'format'] as $key) {
            if (isset($spec[$key])) {
                $property[$key] = $key === 'description' ? $this->text($spec[$key]) : $spec[$key];
            }
        }

        // `of` is the nested half: the fields of an object, or of each item of
        // an array. One key for both, because the declaration reads the same way.
        if (isset($spec['of'])) {
            $nested = $this->object($spec['of']);

            if (in_array('array', $types, true)) {
                $property['items'] = $nested;
            } else {
                $property += array_diff_key($nested, ['type' => null]);
            }
        }

        return $property;
    }

    // ------------------------------------------------------------- the paths

    /** @return array<string, mixed> */
    protected function resourcePaths(): array
    {
        $paths = [];

        foreach (ApiResources::all() as $name => $definition) {
            if (! $definition['query']) {
                continue;
            }

            [$section, $resource] = array_pad(explode('.', $name, 2), 2, null);

            if (! $resource) {
                continue;
            }

            $base = "/api/v2/{$section}/{$resource}";
            $tag = $this->tag($name, $definition);
            $ref = ['$ref' => '#/components/schemas/'.$this->schemaName($name)];

            $paths[$base]['get'] = $this->operation($section, $tag, [
                // Just the verb: the card above already says which resource, and
                // "List :resource" only reads well in a language with no cases.
                'summary' => __('List them'),
                'description' => $this->listDescription($definition),
                'parameters' => $this->listParameters($definition),
                'responses' => [
                    '200' => $this->answer(__('A page of rows.'), [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => $ref],
                            'meta' => ['$ref' => '#/components/schemas/Meta'],
                        ],
                    ]),
                ],
            ]);

            $paths["{$base}/{key}"]['get'] = $this->operation($section, $tag, [
                'summary' => __('Read one'),
                'description' => $this->lookupDescription($definition),
                'parameters' => [$this->keyParameter($definition)],
                'responses' => [
                    '200' => $this->answer(__('The row.'), ['type' => 'object', 'properties' => ['data' => $ref]]),
                    '404' => $this->failure(__('No row is known by that key.')),
                ],
            ]);

            if ($definition['create'] ?? null) {
                $paths[$base]['post'] = $this->operation($section, $tag, [
                    'summary' => __('Create one'),
                    'description' => $this->text($definition['create_description'] ?? null) ?: __('Made by the shop’s own code, exactly as the admin screen makes one.'),
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => $this->object($definition['creatable'] ?? [])]],
                    ],
                    'responses' => [
                        '201' => $this->answer(__('The new row.'), ['type' => 'object', 'properties' => ['data' => $ref]]),
                        '422' => $this->failure(__('The body did not validate.')),
                    ],
                ]);
            }

            if ($definition['update']) {
                $paths["{$base}/{key}"]['patch'] = $this->operation($section, $tag, [
                    'summary' => __('Change one'),
                    'description' => __('A partial write, and only ever partial: a body carrying three fields changes three fields. An exchange must be able to push a stock level without also risking the name, the price and the description.')
                        ."\n\n".$this->writableDescription($definition),
                    'parameters' => [$this->keyParameter($definition)],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => $this->object($definition['writable'] ?? [])]],
                    ],
                    'responses' => [
                        '200' => $this->answer(__('The row as it now stands.'), ['type' => 'object', 'properties' => ['data' => $ref]]),
                        '405' => $this->failure(__('This resource is read-only.')),
                    ],
                ]);
            }

            foreach ($definition['actions'] as $action => $handler) {
                $paths["{$base}/{key}/actions/{$action}"]['post'] = $this->operation($section, $tag, [
                    'summary' => __('Action: :action', ['action' => $action]),
                    'description' => $this->text($handler['description'] ?? null)
                        ?: __('A named transition. It runs the shop’s own code, which is why it is not a write of a column.'),
                    'parameters' => [$this->keyParameter($definition)],
                    'requestBody' => [
                        'required' => (bool) ($handler['body'] ?? []),
                        'content' => ['application/json' => ['schema' => $this->object($handler['body'] ?? [])]],
                    ],
                    'responses' => [
                        '200' => $this->answer(__('The row after the transition.'), ['type' => 'object', 'properties' => ['data' => $ref]]),
                        '404' => $this->failure(__('No such action, or no such row.')),
                    ],
                ]);
            }
        }

        return $paths;
    }

    /**
     * The cart and the checkout: the two things on this API that are not a table
     * with a serializer, and so the two that have to be described by hand.
     *
     * @return array<string, mixed>
     */
    protected function cartPaths(): array
    {
        $cartTag = __('Storefront').' · '.__('Cart');
        $checkoutTag = __('Storefront').' · '.__('Checkout');
        $cart = ['$ref' => '#/components/schemas/Cart'];
        $token = [
            'name' => 'token', 'in' => 'path', 'required' => true,
            'description' => __('The cart’s token, as handed back when it was created.'),
            'schema' => ['type' => 'string'],
        ];

        return [
            '/api/v2/shop/carts' => [
                'post' => $this->operation('shop', $cartTag, [
                    'summary' => __('Create a cart'),
                    'description' => __('Created empty and immediately: the token is what you store, so it has to exist before there is anything in it. Keep it, and it is your cart — an unknown token is a 404, never “wrong token”.'),
                    'responses' => ['201' => $this->answer(__('The new, empty cart.'), ['type' => 'object', 'properties' => ['data' => $cart]])],
                ]),
            ],
            '/api/v2/shop/carts/{token}' => [
                'get' => $this->operation('shop', $cartTag, [
                    'summary' => __('Read the cart'),
                    'parameters' => [$token],
                    'responses' => [
                        '200' => $this->answer(__('The cart.'), ['type' => 'object', 'properties' => ['data' => $cart]]),
                        '404' => $this->failure(__('No cart is known by that token.')),
                    ],
                ]),
                'delete' => $this->operation('shop', $cartTag, [
                    'summary' => __('Empty the cart'),
                    'description' => __('Emptied, not deleted: the token still points at it.'),
                    'parameters' => [$token],
                    'responses' => ['200' => $this->answer(__('The cart, now empty.'), ['type' => 'object', 'properties' => ['data' => $cart]])],
                ]),
            ],
            '/api/v2/shop/carts/{token}/items' => [
                'patch' => $this->operation('shop', $cartTag, [
                    'summary' => __('Set a quantity'),
                    'description' => __('One verb for add, change and remove — `0` takes the line out. “How many of this do you want” is one question, and a client should not have to decide which endpoint it is.'),
                    'parameters' => [$token],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => $this->object([
                            'product' => ['type' => 'string|integer', 'description' => __('Our id, the sku, or the id your own system knows it by.'), 'example' => 'ST-2002'],
                            'quantity' => ['type' => 'integer', 'description' => __('0 removes the line.'), 'example' => 2],
                        ])]],
                    ],
                    'responses' => [
                        '200' => $this->answer(__('The cart as it now stands.'), ['type' => 'object', 'properties' => ['data' => $cart]]),
                        '404' => $this->failure(__('No such cart, or no such product.')),
                    ],
                ]),
            ],
            '/api/v2/shop/carts/{token}/checkout' => [
                'get' => $this->operation('shop', $checkoutTag, [
                    'summary' => __('What has been answered, and what is on offer'),
                    'description' => __('The delivery options are priced for the address given so far — that is why the address is the first question. A `cost` of null means a carrier answered but named no price: the shop will quote it by hand, and a client must not print “free”.'),
                    'parameters' => [$token],
                    'responses' => ['200' => $this->answer(__('The state of the checkout.'), ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/Checkout']]])],
                ]),
                'patch' => $this->operation('shop', $checkoutTag, [
                    'summary' => __('Answer a step'),
                    'description' => __('MERGED onto the answers already given, so a step that picks a delivery does not unanswer the address; a field sent as null is an erasure. Nothing is checked against what is on offer yet — an address typed one field at a time is briefly nonsense, and refusing it would make an ordinary form impossible to build. The answers have to add up at `complete`.'),
                    'parameters' => [$token],
                    'requestBody' => [
                        'required' => true,
                        'content' => ['application/json' => ['schema' => $this->object($this->checkoutAnswers())]],
                    ],
                    'responses' => ['200' => $this->answer(__('The state of the checkout.'), ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/Checkout']]])],
                ]),
            ],
            '/api/v2/shop/carts/{token}/checkout/complete' => [
                'post' => $this->operation('shop', $checkoutTag, [
                    'summary' => __('Place the order'),
                    'description' => __('Everything is re-derived from the stored answers and nothing is trusted from the client, so a delivery option with no rate in the winning zone cannot be bought however the cart was patched. The order is placed by the same code the storefront’s own form runs, which is why stock, statuses, currencies, taxes and the confirmation email behave identically here.'),
                    'parameters' => [$token],
                    'responses' => [
                        '201' => $this->answer(__('The order.'), ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => ['order' => ['$ref' => '#/components/schemas/PlacedOrder']]]]]),
                        '422' => $this->failure(__('The checkout is not complete, or the cart is empty. `errors` names the fields still missing.')),
                    ],
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function checkoutAnswers(): array
    {
        return [
            'customer_name' => 'string',
            'email' => ['type' => 'string', 'format' => 'email'],
            'phone' => 'string|null',
            'address_line' => ['type' => 'string|null', 'description' => __('Required unless the chosen delivery is collected in person.')],
            'city' => 'string|null',
            'country' => ['type' => 'string|null', 'description' => __('Two letters.')],
            'postal_code' => 'string|null',
            'place_id' => ['type' => 'integer|null', 'description' => __('The settlement in the shop’s own locality registry. This, not the town’s name, is what delivery zones match on.')],
            'notes' => 'string|null',
            'shipping_method_id' => ['type' => 'string|null', 'description' => __('One of the ids offered by GET /checkout for these answers.')],
            'shipping_fields' => ['type' => 'object|null', 'description' => __('What a delivery option asked for on top of the address — a branch to collect from, say. Offered options say whether they need any.')],
            'payment_method_id' => 'integer|null',
        ];
    }

    /** @return array<string, mixed> */
    protected function cartSchemas(): array
    {
        return [
            'Cart' => $this->object([
                'token' => 'string',
                'currency' => ['type' => 'string', 'description' => __('What the visitor’s prices are displayed in.')],
                'items' => ['type' => 'array', 'of' => [
                    'product' => ['type' => 'object', 'of' => ['id' => 'integer', 'sku' => 'string', 'external_id' => 'string|null', 'name' => 'string', 'url' => 'string']],
                    'quantity' => 'integer',
                    'unit_price' => ['type' => 'integer', 'description' => __('Minor units. Already reflects any quantity break this line has crossed.')],
                    'line_total' => 'integer',
                    'problem' => ['type' => 'string', 'description' => __('Present only when this line cannot be ordered as it stands — out of stock, or more than is left. Completing the checkout is refused until it is put right.')],
                ]],
                'count' => 'integer',
                'total' => 'integer',
                'coupon' => 'string|null',
            ]),
            'Checkout' => $this->object([
                'answers' => ['type' => 'object', 'description' => __('What has been given so far.')],
                'delivery' => ['type' => 'array', 'description' => __('Priced for the address given so far.'), 'of' => [
                    'id' => 'string',
                    'label' => 'string',
                    'cost' => ['type' => 'integer|null', 'description' => __('Null when the carrier answered but named no price.')],
                    'delivery_time' => 'string|null',
                    'needs_address' => ['type' => 'boolean', 'description' => __('False for an option collected in person, which is what makes the street optional.')],
                    'needs_fields' => ['type' => 'boolean', 'description' => __('The option wants something more from the customer — send it in `shipping_fields`.')],
                    'meta' => 'object',
                ]],
                'payment' => ['type' => 'array', 'of' => [
                    'id' => 'integer',
                    'label' => 'string',
                    'charged_in' => ['type' => 'string', 'description' => __('The currency this method will ask the bank for, when it cannot take the one being shopped in.')],
                ]],
                'currency' => 'string',
                'subtotal' => 'integer',
                'discount' => 'integer',
            ]),
            'PlacedOrder' => $this->object([
                'number' => 'string',
                'status' => 'string',
                'total' => 'integer',
                'currency' => ['type' => 'string', 'description' => __('The shop’s books — the units every amount on the order is stored in.')],
                'payment' => ['type' => 'object', 'of' => [
                    'state' => 'string',
                    'currency' => ['type' => 'string', 'description' => __('What the bank is asked for, which is not always what the order is written in.')],
                    'amount' => 'integer',
                    'url' => ['type' => 'string|null', 'description' => __('Where a browser would have been redirected — a gateway’s own page. Open it in a web view. Null when the method takes no money online.')],
                ]],
            ]),
        ];
    }

    /**
     * A body worth starting from, built from the declared shape.
     *
     * Not an example anybody wrote: a "Try it" box that opens empty is a box
     * nobody presses, and one prefilled with a hand-kept example is prefilled
     * with last year's fields. This is the shape itself, filled with whatever
     * the declaration said a value looks like.
     *
     * @param  array<string, mixed>  $schema
     */
    public function sample(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }

        $types = (array) ($schema['type'] ?? 'string');

        if (in_array('object', $types, true)) {
            return array_map($this->sample(...), $schema['properties'] ?? []);
        }

        if (in_array('array', $types, true)) {
            return isset($schema['items']) ? [$this->sample($schema['items'])] : [];
        }

        return match (true) {
            isset($schema['enum']) => reset($schema['enum']),
            in_array('integer', $types, true), in_array('number', $types, true) => 0,
            in_array('boolean', $types, true) => false,
            in_array('null', $types, true) => null,
            default => '',
        };
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    protected function operation(string $section, string $tag, array $operation): array
    {
        return $operation + [
            'tags' => [$tag],
            // An empty list is not "inherit": it is this operation saying out
            // loud that it needs nothing, which is the whole claim the shop half
            // makes.
            'security' => $section === 'shop' ? [] : [['bearerAuth' => []]],
            'parameters' => [],
        ];
    }

    /** @return array<string, mixed> */
    protected function answer(string $description, array $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /** @return array<string, mixed> */
    protected function failure(string $description): array
    {
        return $this->answer($description, ['$ref' => '#/components/schemas/Error']);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<int, array<string, mixed>>
     */
    protected function listParameters(array $definition): array
    {
        $parameters = [
            ['$ref' => '#/components/parameters/Storefront'],
            ['$ref' => '#/components/parameters/Language'],
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            [
                'name' => 'itemsPerPage', 'in' => 'query',
                'description' => __('Capped rather than refused: ask for ten thousand and you get the ceiling (:max) and a working integration, not a failure at three in the morning.', ['max' => (int) setting('torgash.api', 'max_items_per_page', 200)]),
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];

        foreach ($definition['filters'] as $name => $filter) {
            $parameters[] = array_filter([
                'name' => $name,
                'in' => 'query',
                'description' => $this->text($filter['description'] ?? null),
                'example' => $filter['example'] ?? null,
                'schema' => ['type' => 'string'],
            ], fn ($value) => $value !== null);
        }

        foreach ($definition['sortable'] as $column) {
            $parameters[] = [
                'name' => "order[{$column}]",
                'in' => 'query',
                'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ];
        }

        return $parameters;
    }

    /** @param array<string, mixed> $definition */
    protected function keyParameter(array $definition): array
    {
        return [
            'name' => 'key',
            'in' => 'path',
            'required' => true,
            'description' => $this->lookupDescription($definition),
            'schema' => ['type' => 'string'],
        ];
    }

    /** @param array<string, mixed> $definition */
    protected function lookupDescription(array $definition): string
    {
        $keys = array_merge(['id'], $definition['lookup']);

        return __('Addressed by any of: :keys — so an integration never has to store our autoincrements.', [
            'keys' => '`'.implode('`, `', $keys).'`',
        ]);
    }

    /** @param array<string, mixed> $definition */
    protected function listDescription(array $definition): string
    {
        $own = $this->text($definition['description'] ?? null);

        // Named out loud because it is the difference between a working nightly
        // sync and one that pulls the whole catalogue every time.
        $incremental = isset($definition['filters']['updated_after'])
            ? "\n\n".__('`updated_after` is the filter an exchange cannot work without: everything touched since a moment, rather than everything.')
            : '';

        return trim($own.$incremental);
    }

    /** @param array<string, mixed> $definition */
    protected function writableDescription(array $definition): string
    {
        $fields = array_keys($definition['writable'] ?? []);

        return $fields === []
            ? ''
            : __('Writable here: :fields. Everything else about the row belongs to the shop’s own admin.', [
                'fields' => '`'.implode('`, `', $fields).'`',
            ]);
    }

    /** @param array<string, mixed> $definition */
    protected function tag(string $name, array $definition): string
    {
        [$section] = explode('.', $name, 2);

        // Sectioned, because `admin.products` and `shop.products` are genuinely
        // different resources that happen to share a table — one carries cost
        // prices, the other must never be able to.
        return ($section === 'shop' ? __('Storefront') : __('Admin')).' · '.$this->label($definition);
    }

    /** @param array<string, mixed> $definition */
    protected function label(array $definition): string
    {
        return $this->text($definition['label']) ?? '';
    }

    protected function schemaName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $name)));
    }

    /** A translatable value — a plain string, or a map of locale to string. */
    protected function text(array|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // A declared word is English, translated by its owner's lang file
        // (ADR-005); an old {en, ru} map still reads.
        return label_text($value) ?: null;
    }
}

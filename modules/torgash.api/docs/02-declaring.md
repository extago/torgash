# Putting a module on the API

A module does not write a controller, a route or a page of documentation. It
registers what it has, and everything else — paging, sorting, permissions, error
shapes, the reference page and the OpenAPI document — is applied to it from one
place.

Answer the `api.resources` hook and register:

```php
ApiResources::register('admin.widgets', [
    'label'      => 'Widgets',          // English; the Russian goes in the module's lang/ru.json
    'permission' => 'widgets.manage',
    'query'      => fn () => Widget::query(),
    'serialize'  => fn (Widget $w) => ['id' => $w->id, 'sku' => $w->sku],
    'sortable'   => ['id', 'sku', 'created_at'],
    'lookup'     => ['external_id', 'sku'],
    'filters'    => ['status' => fn ($q, $v) => $q->where('status', $v)],
    'update'     => fn (Widget $w, array $data) => $w->update(...),   // omit for read-only
]);
```

The name is SECTIONED. `admin.widgets` is served at `/api/v2/admin/widgets` and
needs a token; `shop.widgets` is served at `/api/v2/shop/widgets`, is public and
is read-only however it is registered — only GET is routed there. Register both
when the shop should see a narrower version of the same table; do not register
one resource that hides fields conditionally, because that is how a cost price
eventually reaches a storefront.

`lookup` is the part worth naming: `/widgets/1c-42` and `/widgets/W-7` and
`/widgets/4` all find the same row, so an integration never has to store our
autoincrements.

## Saying what it means

Four keys are read by nothing at runtime. They are what the reference page and
`openapi.json` are written from, and they live here rather than in a document of
their own — a description kept somewhere else is a description that is wrong
within a month, usually about the field somebody added on a Friday.

```php
'description' => 'What this resource is, in a sentence or two.',

'schema' => [                                    // what `serialize` answers with
    'id'    => 'integer',
    'price' => ['type' => 'integer', 'description' => 'Minor units of `currency`.'],
    'stock' => 'integer|null',
    'lines' => ['type' => 'array', 'of' => ['sku' => 'string', 'qty' => 'integer']],
],

'writable' => [                                  // what a PATCH may carry
    'stock' => 'integer',
    'status' => ['type' => 'string', 'enum' => ['active', 'hidden']],
],
```

A field is a bare type string or an array with `type`, `description`, `example`,
`enum`, `format` and `of` (the fields of an object, or of each item of an
array). Declare nothing and the page says so — an open object — rather than
inventing a list of fields.

Descriptions are English strings like every declared word; the reference translates them through the module's lang files where a translation exists.

A filter or an action can carry prose the same way, as a closure or as a closure
with a sentence beside it:

```php
'filters' => [
    'updated_after' => [
        'description' => 'Everything touched since a moment (ISO 8601).',
        'example'     => '2026-09-01T00:00:00+03:00',
        'apply'       => fn ($q, $v) => $q->where('updated_at', '>=', $v),
    ],
],
```

## Transitions, not state writes

Anything that is a decision gets an action, never a writable column:

```php
'actions' => [
    'status' => [
        'description' => 'Move it, running the shop’s own code.',
        'body'        => ['status' => ['type' => 'string', 'example' => 'complete']],
        'apply'       => fn (Widget $w, array $data) => app(WidgetMover::class)->move($w, $data['status']),
    ],
],
```

`POST /widgets/{key}/actions/status` runs your code; a `PATCH` setting the
column would have looked like it worked and moved no stock, logged nothing and
sent no email.

## An endpoint that is not a resource

Some things are not a table with a serialiser — the cart and the checkout are
the worked examples. Register the routes yourself and describe them through the
`api.openapi.paths` hook, which hands you the paths and takes them back:

```php
hook_listen('api.openapi.paths', fn (array $paths) => $paths + [
    '/pay/acme/callback' => ['post' => [
        'tags' => ['Shop · Payments'],
        'summary' => 'Acme’s callback',
        'security' => [],
        'responses' => ['200' => ['description' => 'Recorded.']],
    ]],
]);
```

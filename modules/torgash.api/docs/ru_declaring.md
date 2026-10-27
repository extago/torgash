# Как вывести модуль в API

Модуль не пишет ни контроллера, ни маршрута, ни страницы документации. Он
регистрирует то, что у него есть, а всё остальное — постраничность, сортировка,
права, форма ошибок, страница справочника и документ OpenAPI — применяется к нему
из одного места.

Ответьте на хук `api.resources` и зарегистрируйте:

```php
ApiResources::register('admin.widgets', [
    'label'      => 'Widgets',          // по-английски; русский — в lang/ru.json модуля
    'permission' => 'widgets.manage',
    'query'      => fn () => Widget::query(),
    'serialize'  => fn (Widget $w) => ['id' => $w->id, 'sku' => $w->sku],
    'sortable'   => ['id', 'sku', 'created_at'],
    'lookup'     => ['external_id', 'sku'],
    'filters'    => ['status' => fn ($q, $v) => $q->where('status', $v)],
    'update'     => fn (Widget $w, array $data) => $w->update(...),   // уберите — будет только чтение
]);
```

Имя делится на РАЗДЕЛЫ. `admin.widgets` отдаётся по адресу `/api/v2/admin/widgets`
и требует токена; `shop.widgets` отдаётся по `/api/v2/shop/widgets`, публичен и
только для чтения, как его ни регистрируй, — туда проведён только GET.
Регистрируйте оба, когда магазин должен видеть более узкую версию той же
таблицы; не регистрируйте один ресурс, который прячет поля по условию, — именно
так себестоимость однажды и попадает на витрину.

`lookup` — та часть, которую стоит назвать: `/widgets/1c-42`, `/widgets/W-7` и
`/widgets/4` находят одну и ту же строку, так что интеграции никогда не нужно
хранить наши автоинкременты.

## Сказать, что это значит

Четыре ключа во время работы никто не читает. Из них пишутся страница
справочника и `openapi.json`, и живут они здесь, а не в отдельном документе:
описание, которое хранится где-то ещё, через месяц уже неверно — обычно про то
поле, которое кто-то добавил в пятницу.

```php
'description' => 'What this resource is, in a sentence or two.',

'schema' => [                                    // чем отвечает `serialize`
    'id'    => 'integer',
    'price' => ['type' => 'integer', 'description' => 'Minor units of `currency`.'],
    'stock' => 'integer|null',
    'lines' => ['type' => 'array', 'of' => ['sku' => 'string', 'qty' => 'integer']],
],

'writable' => [                                  // что может нести PATCH
    'stock' => 'integer',
    'status' => ['type' => 'string', 'enum' => ['active', 'hidden']],
],
```

Поле — это либо просто строка типа, либо массив с `type`, `description`,
`example`, `enum`, `format` и `of` (поля объекта или каждого элемента массива).
Ничего не объявили — страница так и скажет (открытый объект), а не выдумает
список полей.

Описания — английские строки, как любое объявленное слово; справочник
переводит их через lang-файлы модуля, если перевод есть.

Фильтр или действие могут нести пояснение так же — замыканием или замыканием с
фразой рядом:

```php
'filters' => [
    'updated_after' => [
        'description' => 'Everything touched since a moment (ISO 8601).',
        'example'     => '2026-09-01T00:00:00+03:00',
        'apply'       => fn ($q, $v) => $q->where('updated_at', '>=', $v),
    ],
],
```

## Переходы, а не запись состояния

Всё, что является решением, получает действие, а не записываемую колонку:

```php
'actions' => [
    'status' => [
        'description' => 'Move it, running the shop’s own code.',
        'body'        => ['status' => ['type' => 'string', 'example' => 'complete']],
        'apply'       => fn (Widget $w, array $data) => app(WidgetMover::class)->move($w, $data['status']),
    ],
],
```

`POST /widgets/{key}/actions/status` запускает ваш код; `PATCH`, записавший
колонку, выглядел бы так, будто сработал, — и не сдвинул бы остатки, ничего не
записал в журнал и не отправил письмо.

## Адрес, который не ресурс

Кое-что — не таблица с сериализатором: корзина и оформление — готовые примеры.
Зарегистрируйте маршруты сами и опишите их через хук `api.openapi.paths`,
который отдаёт вам пути и принимает их обратно:

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

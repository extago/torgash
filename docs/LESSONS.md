# Грабли и правила на будущее

> Собрано из явных пометок «технический урок» / «правило на будущее» / «мораль»
> в логе прогресса при разбивке PLAN.md 2026-08-31. Прочитать это ПЕРВЫМ, чтобы
> не наступить повторно. Без привязки к дате; ссылки ведут в
> `docs/progress/2026-08.md` (лог) за полным контекстом.
>
> Короткая каноническая форма части этих правил уже живёт в `CLAUDE.md`
> (разделы «Architecture rules», «HTML gotcha», «Blade gotcha», «Tests»,
> «Background jobs»). Здесь — полный список с контекстом.

---

## Frontend / Blade / daisyUI

Первые два — дословно из бывшего §7.1 PLAN.md (больше нигде не хранятся):

**Важный технический урок (не наступать снова):** HTMX-формы на витрине падали
419 (CSRF), потому что мета-тег `csrf-token` и обработчик `htmx:configRequest`
были только в `admin/layout.blade.php`, а НЕ в `themes/default/views/layouts/storefront.blade.php`.
Теперь есть в обоих layout'ах. Если новый layout/тема добавляет HTMX-формы —
не забыть эту пару (meta-тег + скрипт), иначе тихий 419.

**Второй технический урок:** при кастомизации daisyUI-тем через
`@plugin "daisyui/theme" { ... }` с `themes: false` (полная замена встроенных
тем, не расширение) — обязательно включать `--border`, `--depth`, `--noise`,
иначе часть CSS-правил daisyUI станет "guaranteed-invalid" по спеке `var()`
и молча откатится к начальным значениям. См. §9, запись про баг с рамками.

- **Воркер очереди живёт сутками — кеши в синглтонах тоже** (2026-10-21).
  `SettingsManager` и остальные кеши таблиц сбрасывались только с новым
  процессом: systemd-воркер видел настройки на момент старта, и «Установить
  обновление» не находило версию, которую админка только что показала.
  Кеш строк, которые читает задача, — `PerRequest::flushPerJob()`; то, что
  сбрасывается на запрос (`PerRequest::forget`), сбрасывается и на задачу
  (кроме `sync`: она внутри запроса).
- **PHP из PHP — никогда голым `PHP_BINARY`** (2026-10-20). Это бинарник
  текущего процесса, а под FrankenPHP он — `frankenphp`, и `frankenphp
  artisan …` отвечает «unknown command». Обновление бы закрыло магазин,
  заменило файлы и упало на `update-finish`, а откат — по той же причине.
  Всегда `App\Core\Support\Php::command()` (переменная `PHP_BINARY` →
  `frankenphp php-cli` → Symfony); планировщику Laravel нужна та же переменная.
- **`LC_COLLATE`/`LC_CTYPE` — локали ОС, даже при `LOCALE_PROVIDER icu`**
  (2026-10-20). `LC_COLLATE 'und'` проходит на Windows и падает на первом же
  Linux (`invalid LC_COLLATE locale name`): там это `C.UTF-8`/`en_US.UTF-8`,
  а `und` — только `ICU_LOCALE`. Установщик перебирает имена
  (`Installer::LIBC_LOCALES`) и перед замком проверяет сам поиск
  (`searchWorks()`: lower, ILIKE, to_tsvector, pg_trgm). Проверенное только на
  dev-машине с другой ОС — не проверенное.
- **Поймать исключение шаблона «чтобы страница не упала» — мало** (2026-10-20,
  PLAN §56). `View::render()` при ошибке вызывает `Factory::flushState()`:
  обнуляются секции, стеки, компоненты и счётчик рендера ВСЕЙ страницы. Блок
  в шапке падает, его ловят — а дальше `@yield('content')` пуст и
  `@stack('scripts')` ничего не печатает: громкая 500 превращается в тихую
  страницу без середины. Изолировать только через `Fragment::isolate()`,
  который снимает и возвращает это состояние (и стек циклов `$loop`).
- **Литеральные `@context`/`@type` в `.blade.php` — не писать.** Сканер директив
  Blade читает сырой текст шаблона (даже внутри `@php…@endphp`) и принимает
  `@context` за директиву, портя скомпилированный вид. Собирать такие ключи
  конкатенацией (`$at.'context'`). (§9 «schema.org JSON-LD»; есть в `CLAUDE.md`.)

- **Внутри формы сущности не бывает второй формы.** Браузер выбрасывает
  вложенный `<form>`, но оставляет его инпуты во внешней — скрытый
  `_method=DELETE` протекал в submit товара и удалял товар. Вторую форму
  ставить рядом и связывать `form="id"` или `formaction=`. (§9 «медиа, корзина
  каталога…»; есть в `CLAUDE.md` §HTML gotcha.)

- **HTMX-фрагмент не умеет ни `$request->validate()`, ни `old()`.** Провал
  валидации — это 302 на предыдущую страницу; HTMX подставит её содержимое
  в цель свопа или не подставит ничего, а сообщение осядет в сессии, которую
  фрагмент не читает. Валидировать `Validator::make(...)` и на провал отвечать
  ТЕМ ЖЕ фрагментом с текстом ошибки; значения полей передавать явным массивом,
  а не через `old()`. (§16 2026-09, инлайн-редактор тарифа доставки.)

- **...и отвечать этим фрагментом с кодом 200, а не 422.** HTMX не свопит 4xx
  вообще: честный 422 с перерисованной формой уходит в консоль браузера, а
  покупатель видит, что кнопка не сработала. Незаметно, пока отказы редки
  (honeypot отвечает успехом, лимит ловят единицы), и ежедневно — как только
  включена капча. Глобальный `htmx:beforeSwap` — не решение: тема, которая его
  не скопировала, потеряет поведение так же молча. (§86 2026-09, форма заявки и
  «купить в один клик»; уже было записано в докблоке
  `ProductsController::quickUpdate`.)

- **`:class` на Blade-компоненте — это не Alpine-биндинг.** `<x-icon :class="…">`
  вычисляется на сервере при рендере и в разметку директивой не попадает.
  Клиентскую реактивность вешать на обычный `<span :class="…">` вокруг компонента.
  (§9 «catalog.product.after_buy_box».)

- **`@click.outside` нельзя вешать на саму открываемую панель.** Клик, который
  её открывает, всплывает до document, и `.outside` считает кнопку-триггер
  «снаружи» — панель закрывается тем же кликом. Обработчик — на родителя
  (`<header>`). Та же болезнь погубила первую попытку flyout-подменю профиля.
  (§9 «мега-меню»; §9 «переключатели языка требовали по несколько кликов».)

- **`x-transition.opacity…` оставлял мега-меню в `display:block; opacity:0`** —
  открыта, но невидима. Меню, которое иногда не появляется, хуже меню без
  анимации: анимация убрана. (§9 «мега-меню».)

- **Blade-кэш на Windows/OSPanel не всегда переоткомпилируется по mtime.** Если
  правка в `.blade.php` визуально «не доехала» — сначала `php artisan view:clear`,
  потом искать баг в коде. (§9 «переключатели языка…».)

- **Alpine `:value` пишется в DOM на следующем тике, htmx читает форму в момент
  события.** Первая версия слала предыдущий город и пустой `place_id` без единой
  ошибки. Все изменения адреса — одним событием из `$nextTick`. (§11.8 в
  `docs/progress/2026-08.md`.)

- **`$dispatch` из обработчика внутри `x-for`/`x-if` — подозрителен, если
  обработчик убирает сам себя из DOM.** `$dispatch` шлёт событие с элемента, из
  которого позван; к `$nextTick` кнопки уже нет, событие уходит на оторванный
  узел. Компонент должен запомнить корневой элемент в `init()` и слать
  `CustomEvent` с него. (§11.9 в `docs/progress/2026-08.md`.)

## Laravel / роуты / БД

- **Опциональный route-параметр не может стоять перед литеральным сегментом.**
  `{locale?}/catalog` стабильно 404-ит на голом `/catalog`. Нужен другой
  механизм (per-request выбор формы роута). (§9 «локализованные URL».)

- **Цепочка `.prefix()->prefix()` на одном регистраре перезаписывает, а не
  конкатенирует.** Merge префиксов — только через настоящую вложенность
  `Route::group(fn () => Route::prefix(...)->group(...))`. (§9 «локализованные URL».)

- **`RouteRegistrar::where()` принимает только `array` (`where(['name'=>…])`)** —
  перегрузка `where($name, $pattern)` есть лишь у объекта `Route`. Иначе
  `TypeError` уже на `route:list`. (§9 «локализованные URL».)

- **Роут на `*.php` до приложения не доходит, пока такого файла нет.** Caddy,
  типовой nginx (`location ~ \.php$`) и Apache отдают `.php` в PHP только при
  существующем файле, иначе отвечают 404 сами: `Route::get('/1c_exchange.php')`
  зарегистрирован, в `route:list` виден и никогда не срабатывает. И даже когда
  файл появился, нужны три `$_SERVER` (`SCRIPT_NAME`/`SCRIPT_FILENAME`/
  `PHP_SELF` → `index.php`): Symfony считает базовый URL из `SCRIPT_NAME`, и
  без правки весь путь оказывается именем скрипта, а запрос приезжает на
  главную. (§66 лога; `Torgash\CommerceMl\LegacyEndpoint`.)

- **Per-request регистрация роутов несовместима с `php artisan route:cache`.**
  Записано в докблоке `LocaleResolver`; в проекте `route:cache` пока не
  используется. (§9 «локализованные URL».)

- **Внешний API, который дёргается на каждую котировку, нужно гасить после
  отказа.** У НП лимит запросов на ключ; котировка идёт на каждое нажатие
  клавиши в поле города, и повторная попытка после «To many requests»
  превращает короткую блокировку в постоянную. После неудачи — отдельный
  маркер и пауза (у нас 2 минуты), а чекаут работает по кэшу. (§25 2026-09.)

- **Лимит внешнего API нужно ИЗМЕРИТЬ, а не обходить ретраями.** У НП, замерено:
  без пауз ключ получает 10 ответов и упирается в «To many requests», через
  0,35 с — 11, через 0,7 с — 23, а через **1,5 с — 40 из 40 без единого отказа**;
  сам отказ снимается за ~5 с. То есть это скользящий бюджет, а не бан, и
  правильное лекарство — темп (`Client::paced()`), а сверху короткий ретрай.
  Массовая работа темп держит, котировка в чекауте — никогда: она делает
  один-два запроса, и спать в запросе покупателя не за что. (§27 2026-09.)

- **API Новой Почты: слэш в конце URL обязателен, скаляры — строки.**
  `…/v2.0/json` без слэша отдаёт 301 на `…/json/`, редирект превращает POST в
  GET, тело теряется, и ЛЮБОЙ запрос возвращает «Data is invalid» — с ключом,
  без ключа, с чужим ключом. Выглядит как проблема с ключом, ей не является.
  `Page`/`Limit` числом → «Page is invalid format». (§18 2026-09.)

- **Класс, который читают и билдером, и через модель, получает jsonb в двух
  видах.** Сырая строка от Query Builder против уже распакованного массива у
  Eloquent — `(string) $model->jsonbColumn` даёт «Array to string conversion»
  ровно в том месте, где вызов пришёл со стороны админки. Принимать оба.
  (§18 2026-09, `PlaceMatcher`.)

- **Дедупликация «одинаковых» строк должна идти по тому же ключу, по которому
  потом сравнивают.** `array_unique` по сырым именам оставил «П'ятихатки» и
  «Пятихатки» как два кандидата с одним нормализованным ключом — и правило
  «ровно один кандидат» не срабатывало никогда, а защита «ближайший вдвое ближе
  следующего» сравнивала пункт с его же дубликатом. Дедуплицировать по
  нормализованному ключу и по id сущности. (§21 2026-09, матчер НП.)

- **«Эти два кандидата — одно и то же место» меряется между КАНДИДАТАМИ, а не
  расстоянием до цели.** Два села в 11 км по разные стороны одинаково далеки от
  нас, но между ними 22 км. (§21 2026-09.)

- **Сортировка, которая добавляет JOIN, ломает соседний неквалифицированный
  `where`.** `where('country', …)` живёт годами, пока одна из сортировок не
  подцепит таблицу с такой же колонкой — тогда «ambiguous column» и 500, но
  только на этом варианте сортировки. Квалифицировать ВСЕ колонки в таком
  запросе и тестировать каждую сортируемую колонку, а не две из трёх.
  (§20 2026-09, список мест НП.)

- **`latest('id')` поверх отношения с собственным `orderBy` не отменяет его,**
  а дописывается следом: `hasMany(...)->oldest()` + `latest('id')` = сортировка
  по created_at, и при одинаковых секундах берётся не та строка (флейка, которая
  проходит поодиночке и падает в полном прогоне). Нужен `reorder('id','desc')`.
  (§20 2026-09.)

- **`pluck(DB::raw('coalesce(…)'), 'id')` молча ломается.** Query Builder ищет
  у строки СВОЙСТВО с именем последнего сегмента выражения после точки — для
  `coalesce(pf.value_text, fv.code)` это `code)`, и падает
  `Undefined property: stdClass::$code)`. Нужен явный алиас: `selectRaw('… as
  raw_value')` + `get()->keyBy(...)`. (§17 2026-09, резолвер веса.)

- **`to_tsquery` не для сырого пользовательского ввода** (`& | ! ( ) :` —
  операторы; дефисы в артикулах ломаются). Брать `websearch_to_tsquery`.
  Для опечаток — `word_similarity()`, не `similarity()` (длинное имя разбавляет
  короткий запрос). (§9 «поиск».)

- **`backoff()` — зарезервированное имя у джобы.** Laravel вызывает его при
  постановке в очередь, чтобы узнать паузу перед ретраем, и делает это
  ИЗВНЕ класса: свой `protected function backoff()` роняет не тест, а
  `dispatch()` — `Call to protected method … from scope Illuminate\Queue\Queue`.
  То же про `$backoff`, `retryUntil()`, `$tries`, `$timeout`. Своё называть
  иначе (`pauseAfterRefusal()`). (§27 2026-09, массовая тяга отделений.)

- **БД под локалью C молча ломает кириллицу** (`lower()` не трогает,
  `ILIKE` не фолдит регистр, `pg_trgm` даёт 0 триграмм). Создавать с
  `LOCALE_PROVIDER icu ICU_LOCALE 'und'`. (§10.10, §10.12 в
  `docs/progress/2026-08.md`; развёрнуто в `CLAUDE.md` §Environment.)

## Данные локалей (OSM / GeoNames)

- **Код региона — не его идентичность.** ISO зовёт Днепропетровскую «12»,
  GeoNames «12» — это Киев. Искать по `ref`, код — атрибут. (§10.18 в
  `docs/progress/2026-08.md`.)

- **Перенумеровать множество по одному нельзя** — `UPDATE code=05` падает на
  уникальном индексе, пока «05» держит непереехавший регион. Две фазы: парковка
  на `~<id>`, потом раздача. (§10.18–§10.19 в `docs/progress/2026-08.md`.)

- **`shapely.STRtree.query(point, predicate=…)` применяет предикат как
  `predicate(вход, элемент дерева)`** — нужен `within`, не `covers`. И побеждает
  самый МЕЛКИЙ подходящий полигон (Москва, а не Московская область). (§10.19 в
  `docs/progress/2026-08.md`.)

- **Не просить pyosmium собирать площади прямо из экстракта** — это собрать
  каждое здание страны (Россия ≈ час). Сначала вырезать границы
  `BackReferenceWriter`-ом в отдельный файл, потом полигоны из него (125 с).
  (§10.19 в `docs/progress/2026-08.md`.)

## Лицензии

- **Сторонний JS/ассеты — только MIT/BSD/ISC/Apache.** Движок бесплатен, но
 ecosystem платный: GPL-копилефт (открытая ветка Fancybox) и per-seat
  коммерция одинаково исключены. PhotoSwipe (MIT) вместо Fancybox. (§9
  «лайтбокс»; есть в `CLAUDE.md`.)

- **Пакет локалей из OSM несёт ODbL 1.0 (share-alike) + «© OpenStreetMap
  contributors».** На лицензию самого движка не влияет (данные не заражают код),
  но пакет в поставке — копилефт, в отличие от GeoNames (CC BY 4.0). (§10.18 в
  `docs/progress/2026-08.md`.)

## Тесты / harness

- **Тесты переиспользуют одно приложение на все запросы** — относительный URL
  собирается генератором с корнем от предыдущего запроса. После обращения к
  другому хосту писать хост явно (`$this->get('http://localhost/…')`).
  (§9 «тесты»; есть в `CLAUDE.md` §Tests.)

- **Per-request memo-синглтоны (`Currencies`, `Labels`, `PriceTypes`, settings)
  тёплые после первого запроса** — счётчики запросов мерить со ВТОРОГО.
  `QueryBudgetTest` — ловушка на возврат N+1 (сетка на 3 и на 12 товаров обязана
  стоить одинаково). (§9 «тесты»; есть в `CLAUDE.md`.)

- **`@include` наследует ВСЮ область видимости вызывающего шаблона.** Партиал,
  который по умолчанию берёт переменную с обычным именем (`$field = $field ??
  'media'`), молча подхватит то, что оставил после себя вызывающий. Форма товара
  за 200 строк до включения галереи делала `@foreach ([...] as $field => $label)`
  по габаритам — галерея получила `'height'` и назвала свои инпуты
  `height[12][alt]`. Три симптома, ни один не рядом с причиной: подписи фото
  никогда не сохранялись, `height` уходил массивом и валился на валидации, а
  перерисованная форма падала 500 на `old('height')`. В партиале — имя, которое
  никто не оставит случайно (`$galleryField`). (§55 лога; `ProductFormGalleryTest`.)

- **Postgres `jsonb` НЕ хранит порядок ключей** — сортирует по длине, потом
  побайтово: `{"zzz":1,"a":2,"mmmm":3,"bb":4}` читается как
  `{"a":2,"bb":4,"zzz":1,"mmmm":3}` (замерено). Всё, где порядок важен,
  хранится отдельным МАССИВОМ (массивы порядок держат) — так сделан
  `features_layout` у товара. (§55 лога; `ProductFeatureLayoutTest`.)

- **Компилятор КОМПОНЕНТОВ Blade тоже читает сырой текст шаблона — включая
  комментарии.** Литеральный тег компонента в `{{-- --}}` или даже в
  PHP-комментарии внутри `@props([...])` компилируется в вызов компонента
  прямо там: получается `Undefined variable $component` в месте, где никакого
  компонента нет. Родня уже записанной ловушки с литеральным `@directive` в
  строке. В комментариях писать без угловых скобок. (§56 лога.)

- **Именованный слот ПЕРЕД содержимым компонента оставляет незакрытый
  output-буфер.** Замерено: `ob_get_level()` 1 → 2 для
  `<x-c><x-slot:trigger>…</x-slot:trigger>тело</x-c>` и 1 → 1, если слот
  стоит после тела; от имени слота не зависит. PHPUnit метит такой тест
  risky, а на странице лишний буфер глотает вывод. Поэтому у `x-modal`
  триггер — АТРИБУТ, а не слот: его естественно писать первым, то есть
  удобная форма записи — она же ошибочная. (§56 лога; `ModalComponentTest`.)

## Переводы

- **JSON-перевод модуля перекрывается КОРНЕВЫМ `lang/<locale>.json` по точному
  совпадению английской строки, безо всякой оглядки на контекст.** Укусило
  дважды за один вечер: `__('Everything')` в описании условий акции печаталось
  как «Все права» — корневая строка экрана групп доступа, где она значит «все
  разрешения», — а `__('Any')` в фильтре по акциям выходило «Любой» вместо
  «Любая». Ключ здесь и есть сама фраза, поэтому два модуля, независимо
  выбравшие одно английское слово, делят один перевод, и выигрывает не тот,
  кто ближе к месту, а корень. Правило: в модуле не брать общеупотребительное
  слово в одиночку — писать строку, уникальную по смыслу («matches
  everything», «Any promotion»), и заодно смотреть, нет ли уже такого ключа в
  `lang/ru.json`. Дубли внутри своего файла ловятся так:

      grep -o '^\s*"[^"]*"' modules/<id>/lang/ru.json | sort | uniq -d

  (§24 лога, акции.)

- **И то же самое между двумя МОДУЛЯМИ, где выигрывает не корень, а порядок
  загрузки.** Второй фактор (§92) собирался положить в `torgash.guard/lang/ru.json`
  ключи `"On"`, `"Off"` и `"Last used"`. Первые два уже заняты — `"Off": "Выкл"`
  у автоматизаций и `"On": "Включено"` у платежей, — а третий у КОРНЯ
  (`"Последний вызов"`, экран токенов API). Итог был бы такой: в списке правил
  автоматизаций «Выкл» превратилось бы в «Выключена» (или нет — как сложится
  порядок модулей), а на своей же странице вышло бы «Последний вызов» вместо
  нужного. Правило то же и проверка та же:

      grep -rn '"Off"' modules/*/lang/ru.json lang/ru.json

  Оба лечатся одинаково — ключ, уникальный по смыслу: `Turned on` / `Turned off`
  / `Last confirmed`. Общеупотребительное слово в одиночку в модуль не кладём
  ВООБЩЕ, даже если корень его пока не занял: занять может любой следующий
  модуль, и сломается при этом чужой экран.


## Инструментарий (не про код)

- **Координаты кликов через CDP ≠ CSS-пиксели.** Окно 1920 CSS-px, скриншот
  1568 — масштаб 0.817. Переводить `screenshotX = cssX * 1568 / innerWidth`,
  проверять `innerWidth` перед кликом по координатам. (§9 «мега-меню».)

- **Для новых (не присутствовавших при рендере) строк Alpine-репитера надёжнее
  координатный клик, чем `form_input` по нескольким полям подряд** — второй
  вызов не долетает до реактивного состояния. (§9 «метка „Хит"».)

## A signed URL cannot be a key for a page that exists per locale

Every storefront page here is registered once per locale (`/x` and `/ru/x`, two
route names, one page). Laravel's `URL::signedRoute()` / `hasValidSignature()`
hash the WHOLE address, so a signature made for one shape is invalid on the
other — and the language switcher carries the query string across, as it must
for filters and paging, so one click turns a working link into a 404.

Sign a canonical STRING instead (what the key is about, not where it points):
`Torgash\Checkout\Orders\OrderKey` is the worked example. And do not compute
that canonical value with `URL::signedRoute()` and a bare route name either: the
url generator is locale-aware and rewrites the name into the shape the current
request came in as, which puts the same bug one floor down.

## A "restore to this warehouse" map is only meaningful for a split product

`Product::adjustStock($qty, ['restore' => [$id => -$qty]])` names warehouses, and
`StockAllocator` used to honour that map for ANY product. For one whose stock is
not kept per warehouse, the map created its first warehouse row — after which
`syncAggregate()` sets `m_catalog_products.stock` to the sum of the rows, i.e. to
just those units, silently discarding whatever the column said. It never bit,
because the only caller passed a map lifted off an order line, which only exists
for a product that was already split; the third argument added for returns
("where the parcel actually arrived") is the first that can name a warehouse out
of nowhere.

The rule: a restore map is honoured only when `isSplit()`, and otherwise the
caller falls through to the plain column update. And when the named warehouse
exists but has no row for this product, credit THAT warehouse rather than the
default one — the goods are physically on that shelf. The default is for the
other case, a warehouse deleted since (its rows went with it by cascade), where
the units still have to land somewhere.

## "The mime says image" is not "the bytes are an image"

`UploadedFile::getMimeType()` sniffs the file, which is what stops `shell.php`
being stored under a name a web server hands to PHP. It does NOT promise the
file can be decoded: a truncated upload, or a JPEG header with rubbish behind
it, sniffs as `image/jpeg` and then throws `CouldNotLoadImage` out of the
thumbnail pass — after the media row is already written. On an admin form that
is a 500 on a product photo; on a public one it is a 500 that throws away
everything the person typed.

So `ImageProcessor` requires `getimagesize()` to succeed as well, next to the
mime check and before anything is stored — it was already calling it for the
dimensions, so the check is free. And a public upload path keeps a wide
`catch (Throwable)` besides: a form must not answer 500 because of a file a
stranger chose.

Testing this needs a REAL file. `UploadedFile::fake()->createWithContent()`
takes its mime from the extension, so a "disguised script" built that way sniffs
as an image and proves nothing. Write a temp file and pass
`new UploadedFile($path, $name, null, null, true)` so Symfony detects the type
from the bytes.

## A filter whose default is not "everything" needs a marker of its own

A browser sends nothing at all for an unticked checkbox. So on a list whose
default is a FILTER — the enquiries inbox opens on the open ones, not on the
archive — "untick everything and press Filter" arrives at the server looking
exactly like a first visit, the default comes back, and the button appears to do
nothing while the archive stays unreachable.

The fix is one hidden field inside the form (`status_set=1`), meaning "this set
is the person's own choice"; an empty set then honestly means every state. Read
it as `$request->has('status_set') || $request->has('status')`, so an older
bookmark carrying a single `?status=done` still reads as the choice it was.

The same shape is worth remembering for any default that is not the empty
filter: the value cannot carry the difference between "not chosen" and "chosen
to be empty", so something beside it has to.

## A per-request singleton is only per-request if it asks which request

A singleton holding a counter or a memo for "this request" survives the request
under a persistent worker — and in the test suite, where one application serves
many requests in a row. `Torgash\Guard\Audit\Auditor` keeps the request object
it last decided about and resets its counters when it changes; that is what
makes "am I in the admin", "how many rows have I written" and "have I hit the
cap" mean this request rather than some earlier one. Same family as the rule
about `static` properties in CLAUDE.md, one floor up.

## Anything a background run stores for a human to read later is a key, not a sentence

The rule was already written down for `TrackedJob` messages, and it was learned
again on the invariants screen: the nightly check ran in the application's
default language, so the finding it stored read "Groups marked as default: 0"
under a Russian heading for ever after.

The test is not "is this text user-visible" but **"who writes it, and who reads
it"**: whenever those are two different moments, store the translation key and
its parameters (`title` + `title_params`) and call `__()` where it is read. A
value that is data rather than a sentence — an order number, an sku — goes in as
a key with no translation and comes back out unchanged, so one column serves
both.

The same trap eats parameters: a param whose value is itself a translated word
freezes just as hard. `Torgash\Checkout\Invariants\CheckoutInvariants` stores
the payment state's CODE (`paid`) rather than its label for exactly that reason.

## Re-running a vendoring script upgrades what it does not pin

`modules/torgash.editor/tools/vendor-tiptap.php` exists to add a TipTap package
to `assets/vendor/`. Adding `@tiptap/extension-image` meant running it again —
and the run rewrote `prosemirror-transform.mjs` and `prosemirror-view.mjs` too,
because the script pins `@tiptap/*` to two version constants and does NOT pin
the prosemirror-* packages esm.sh resolves underneath them. Nothing warned:
the output looked like a normal 31-file listing.

Taken as it stood, a one-line feature would have carried a ProseMirror bump into
the same commit — the kind that surfaces weeks later as "the editor sometimes
loses a selection" and is then bisected against a diff about images.

The habit, for any vendoring script: **write into a temp directory, diff it
against what is there, and copy only what you meant to add.**

    php.exe modules/torgash.editor/tools/vendor-tiptap.php %TEMP%/vendor-new
    diff -rq modules/torgash.editor/assets/vendor %TEMP%/vendor-new

Here only `tiptap-extension-image.mjs` was copied in (it imports nothing but
`@tiptap/core`, so it does not need the newer ProseMirror). A ProseMirror
upgrade is a separate change with its own testing. (§93 лога.)

## Middleware pushed onto a router group from a module can be silently undone

`torgash.guard`'s blocking needed one check in front of every `web` request, so
the provider called `$router->pushMiddlewareToGroup('web', …)`. It worked in the
browser and did nothing at all under the test suite — no error, no warning, the
middleware simply never ran.

The HTTP kernel OWNS the middleware groups and copies them onto the router when
it is resolved (`syncMiddlewareToRouter()`), so whichever of the two happens
later wins. In production the kernel is resolved before the providers boot and
the push survives; in the test suite the console kernel bootstraps the
application and the HTTP one is resolved at the first request — after boot —
and the sync overwrites the group.

The fix is to say it in the place that cannot be overwritten:

    $append = fn ($kernel) => $kernel->appendMiddlewareToGroup('web', CheckBlock::class);
    $this->app->afterResolving(HttpKernel::class, $append);
    if ($this->app->resolved(HttpKernel::class)) { $append($this->app->make(HttpKernel::class)); }

Both orders covered, and `appendMiddlewareToGroup()` dedupes itself. The general
shape: **when the framework keeps two copies of a list, write to the one it
copies FROM** — and when a feature works in the browser and not in the tests,
suspect an ordering difference between the two before suspecting the test.


## A file in `public/` silently wins over the route with the same address

The `.php` lesson above has a mirror image, and it bit in the opposite
direction: `/robots.txt` was made a route, `route:list` showed it, and the
server kept answering with `public/robots.txt` — the two-line file that has been
sitting there since the first commit. The web server hands a request to PHP only
when it cannot serve it from the document root itself, so ANY real file shadows
a route of the same name, quietly and with a 200.

So a route that replaces something previously shipped as a file has to DELETE
that file in the same change (`git rm public/robots.txt`), and a deployment that
restores it turns the feature off with no error anywhere. Worth remembering for
every other well-known name: `sitemap.xml`, `favicon.ico`, `ads.txt`,
`.well-known/*`. (§94 лога.)

## A route parameter swallows a suffix, so the suffixed route goes FIRST

`/product/{slug}` and `/product/{slug}.md` both match `/product/x.md`: the
default pattern is `[^/]+`, greedy, and it happily takes `x.md` as the slug.
Laravel matches in registration order, so registering the plain page first means
the `.md` route is dead — and the failure is not a routing error but a 404 from
the controller, which reads as "no such product".

Register the more specific one first (or constrain the parameter). Pinned by a
test that asks for both addresses, because the two routes look independent in
the file and are not. (§94 лога.)

## A fallback route cannot carry route middleware

`Route::fallback()` is right whenever a module answers "whatever nobody else
claimed" — a page at a root address, for instance — precisely because it runs
last. The price is that a rule you would otherwise hang on the route (a
throttle) has nowhere to go: registering a real route for the same shape would
put it BEFORE the others and let it swallow their addresses, and that ordering
then depends on which provider booted first.

The way out is to call the middleware by hand rather than write a second rule
beside it:

    app(ThrottleRequests::class)->handle($request, fn () => response(''), 'torgash-agent');

Same limiter, same 429, same `Retry-After` (`App\Core\Seo\AgentTraffic`). The
general shape: **when a route cannot hold the rule, invoke the framework's own
implementation of it — do not reimplement it locally.** (§94 лога.)

## Middleware in the `web` group never sees the address that matched nothing

Group middleware is resolved after the router has found a route, so a request
to `//catalog` or `/Catalog` — which matches nothing — is a 404 raised before
any of it runs. That is fatal for anything whose whole job is to fix a
malformed address: URL normalisation put in `web` would only ever have tidied
addresses that already worked.

Such a rule goes on the GLOBAL stack (`$middleware->append(...)` in
`bootstrap/app.php`), appended rather than prepended so `TrustProxies` has
already decided what the scheme and host are. The price is that everything the
`web` group sets up is absent — no session, no resolved storefront — so the
rule may only read shop-wide settings and must build its own URLs from the
request (`$request->getSchemeAndHttpHost()`), never from `url()`, which would
hand a multi-domain shop whatever `APP_URL` names. (§95 лога,
`App\Core\Seo\NormaliseUrl`.)

## Laravel's test client trims a trailing slash off the URL you give it

`$this->get('/catalog/')` never sends `/catalog/`: `prepareUrlForRequest()`
does `trim(url($uri), '/')` first, so the request the application sees is
`/catalog`. Anything about trailing slashes therefore cannot be tested through
`get()` at all — the test would be measuring the helper. Build the request and
call the code under test directly instead:

    (new NormaliseUrl)->handle(Request::create('http://localhost/catalog/'), fn () => response(''));

Interior double slashes and capitals do survive the helper, so only the
trailing-slash case needs this. (§95 лога, `Core\PageHeadTest`.)

## `scoped()` declares "one per request"; only Octane makes it true

A `scoped` binding is forgotten between requests by `forgetScopedInstances()`,
and Octane is the only thing that calls it. Under php-fpm nothing notices,
because each request builds a new application — and the test suite notices in
the worst possible way: the second request in a test gets the first request's
object. Measured: `App\Core\Seo\PageMeta` answers its filter once and remembers
that it did, so on the second request the filter never ran, the controller's own
`set()` overwrote what a template had written, and the page printed the previous
page's description. Silent, and green everywhere except in a test with two
requests in it.

Hang the reset on the one event that means "a new request has begun" in every
environment — the container rebinding `request`:

    App\Core\Support\PerRequest::forget($app, PageMeta::class, ...);

Use it for anything that accumulates state ABOUT the current page. A memo of a
small table (Currencies, Labels) does not need it: stale rows there are a cache
question, not a correctness one. (§96 лога.)

## Postgres compares a GROUP BY expression textually, so bindings break it

    ->selectRaw("coalesce(meta_title->>?, '') as title", [$locale])
    ->groupByRaw("coalesce(meta_title->>?, '')", [$locale])

is rejected with *"column must appear in the GROUP BY clause"* even though the
two expressions are identical as written: by the time Postgres sees them they
carry different placeholder numbers (`$1` and `$3`), and it matches grouped
expressions by their text. Group by the select POSITION instead — `groupByRaw('1')`
— which also removes the second copy of the expression, so the two can no
longer drift. (§98 лога, `CatalogInvariants::duplicateTitles()`.)

## A fallback route makes every path "routable"

`Route::getRoutes()->match(Request::create($path))` never throws once any module
has registered `Route::fallback()` — the pages module does — so a check built on
"does a route match this address" can neither find a broken one nor clear a good
one. That was a whole invariant, written and thrown away: a statement that
cannot be false is not a statement. Where the question is really "does this
address answer", the only honest implementation asks the shop over HTTP, and
then it belongs in a `manual` check rather than a nightly sweep. (§98 лога,
`SeoInvariants`.)

## A front controller is stripped before anything in PHP sees the address

`/index.php?dispatch=products.view&product_id=12` — the shape of nearly every
link a shop on an older engine earned — arrives with `getPathInfo()` of `/`, so the
HOME PAGE answers it with a cheerful 200 and no 404 handler ever runs. Anything
that has to redirect such an address must act before routing (`NormaliseUrl`
does), and must build its target from `getSchemeAndHttpHost()`: the framework's
base path is `/index.php` on that request, so a relative redirect comes back out
as `/index.php/product/x`. Store and look up the query string NORMALISED
(`Request::normalizeQueryString`), or `?a=1&b=2` and `?b=2&a=1` are two rows.
(§98 лога, `App\Core\Seo\RedirectManager`.)

## A tree read as a flat list is wrong in a way that renders perfectly

`Category::orderBy('sort')->get()` was in sixteen screens, and every rule that
named a category compared DIRECT membership. Both are invisible when wrong. A
shop keeps its products in the leaves, so the parent category pages —
"Электроника", "Дом и кухня" — rendered a flawless EMPTY grid; the sidebar
listed root categories only, so the whole second level was linked from no page
at all; a promotion on "Электроника" discounted nothing; a label rule's own
"incl. subcategories" box went exactly one level down, so a grandchild was
missed. Nothing errored, nothing logged, and the suite was green before and
after the fix — because nothing had ever pinned the behaviour.

Two rules came out of it. **State the tree once**
(`Torgash\Catalog\Support\CategoryTree`): descendants, ancestors, tree order and
the indented picker list, memoised per request, and cycle-safe — a looping
`parent_id` is a corrupt row, not an impossible one, and a walk that trusts the
data hangs the request. And **a category is its subcategories** everywhere, with
no switch: the one place where "and below" is genuinely a choice is how many
categories a GENERATOR writes pages in, and that one says so on its own row.

The picker half is the same lesson in the admin: a flat list sorted by `sort`
interleaves the levels, so the order a shop owner reads bears no relation to the
tree they built, and two categories called "Кофе" in different branches are
indistinguishable. (§100 лога, `CategoryTree`, `CategoryTreeTest`.)

## A second copy of a control is a second source of truth, not a second view

The catalogue had one `<form method="GET">` holding TWO renditions of the same
filters — a sidebar and a pill row above the grid — with the same input names.
The browser submits everything a form holds, so every value went twice
(`?price_min=&price_max=&feature[8][]=22&price_min=&price_max=`), and unticking
a box was IMPOSSIBLE: clearing one copy left the other ticked and still
submitting, so the filter never changed. Reported from the shop as two separate
complaints; it was one cause.

Hiding one copy with CSS does not fix it — a `display:none` input is submitted
like any other, and so is a `disabled`-less one behind a media query. The only
fix is one set of controls. Anything else showing the same state must be LINKS:
a chip that says what is filtered and carries the address that drops it cannot
be half of a form's state.

Two more, from the same family: a GET form submits every box it holds, so an
untouched price field rides along as `?price_min=` (disable empty inputs on
`submit` — a disabled input is not sent); and never build such a link with
`http_build_query()`, which numbers a list (`feature[8][0]=22`) and so spells
the address differently from the checkboxes that produced it. Symfony's
`Request::getQueryString()` does the same thing, which is how a language
switcher silently re-spelled the URL a visitor was looking at. (§101 лога,
`CatalogFiltersTest`.)

## An option that leads nowhere is worse than no option

The Clothing page offered a choice between 8 and 16 gigabytes of memory for a
jumper, and either answer led to an empty grid. Nothing was broken: a
characteristic attached to no category applies everywhere by design (that is how
Brand and Colour work), and nobody had asked whether there was anything on THIS
page to filter by. A control that cannot narrow anything still reads as a
promise, so it has to be measured against what is actually listed and removed —
the characteristic and its individual values alike.

The trap on the way is measuring against the CURRENT SELECTION instead of the
category: pick "Steel" and steel becomes the only material on offer, so there is
no way to widen again without editing the address by hand. Availability is a
property of the page, not of what has already been ticked.

The same shape appears wherever a shop offers a list it did not compute: a
delivery method with no rate, a payment method for a currency the gateway
refuses, a language with no translated route. (§103 лога,
`StorefrontController::narrowToWhatIsHere()`.)

## A Blade directive written right after a word character is not a directive

`{{ $label }}@if ($price) … @endif@unless ($orderable) — sold out @endunless`
looks like two conditions in a row and compiles into a half-open one: Blade's
directive pattern begins with `\B@`, so an `@` preceded by a WORD character
(the `f` of `@endif`) is not the start of a directive at all. The `@unless` was
left in the output as literal text while its `@endunless` — preceded by `}` —
compiled normally, and the view died with `syntax error, unexpected token
"endif"` (measured: the options set fragment 500'd, and the error pointed at a
line in `storage/framework/views` rather than at the template).

A space would be enough, but the honest fix for a one-line label is to build
the suffix in `@php` and print it: a label made of three conditional pieces is
a string, not markup.

The same scanner is why a literal `@context` must never appear in a blade file
(even inside a comment or `@php`), and why a component tag inside `{{-- --}}`
is still compiled. One rule: Blade reads the RAW text, and what it recognises
there does not depend on where you think you are.

## `<x-icon>` in a `text-center` cell sits at the left

Tailwind's preflight makes every `svg` `display: block`, and `text-align`
centres inline content only — so a column whose header is centred shows its
ticks and crosses hugging the cell's left edge, while a `—` in the same column
(text) sits in the middle. Give the icon `inline-block` (or centre the cell with
flex). Found on the availability statuses list, 2026-09-25.

## A hidden panel still widens a phone's page

`visibility: hidden` (and `opacity: 0`) keep an element in the layout, so an
absolutely placed hint that would stick out past the right edge when open
makes the whole page scroll sideways while closed. Measured on the product
page at 390px: the labels' hints made it 410 wide. A closed panel is
`display: none` (with `transition-behavior: allow-discrete` /
`@starting-style` for the fade), and an open one is capped at the viewport.
The same check found a `grid` with one `1fr` column refusing to shrink below
its `truncate` text — a grid item's minimum is its content, so it needs
`min-w-0` exactly like a flex item. Look for it with
`document.documentElement.scrollWidth > clientWidth`. Found 2026-10-09.

## Alpine's `$el` in a method is whoever called it

In an `Alpine.data()` method, `this.$el` is the element whose directive ran
the call — for `x-on:click="enter()"` on a toolbar button, that BUTTON, not
the component's root. The 3D viewer asked `this.$el.requestFullscreen()` and
the browser gave a 36px button the whole screen: black, no error anywhere.
The component's own element is `this.$root`. `$el` is the root only inside
`init()` and directives on the root itself. Found 2026-10-17.

## HTMX does not send a request whose target is missing

Every buy form aimed at the header badge (`hx-target="#cart-badge"`). The
cart and checkout draw a quiet header WITHOUT that badge, on purpose — and
HTMX, finding no target, raises `htmx:targetError` and never sends the
request. So "You may also like" under the basket added nothing, with nothing
in the network tab and nothing on screen. A form that can appear on more than
one kind of page must not aim at something only some pages have: swap
nothing (`hx-swap="none"`) and let the answer bring out of band what the page
says it has (`CartController::SHOWS`, filled by cart.js) — out of band only
for what exists, because a fragment with nowhere to go is an HTMX error too.
Found 2026-10-21.

## Under a worker, what was booted is shared; what a request resolves is not

Octane clones the booted application for every request, so it is tempting to
think worker mode is "the same, only faster". It is for anything a request
RESOLVES — the clone dies with it. It is not for anything resolved WHILE
BOOTING: those are the same objects for every request of that worker. Found
on the first pass (2026-10-21), each now in `WorkerModeTest`: an admin button
that `hook_listen()`-ed a code channel for one call left it subscribed; a
storefront on its own theme rewired the shared view finder and the admin drew
in that theme next; the themes screen ADDED every theme's translations to the
shared translator; a setting read once was never read again, though another
worker had changed it. And one that php-fpm hides completely: a `rebinding`
callback that resets through the container it CAPTURED resets the booted
original, not the clone serving the request — use the container it is handed.

## A release cannot bring a path the installed code does not let it own

What an update may replace is decided by the code ALREADY INSTALLED
(`Distribution::ownable`, a guard that keeps a package away from `.env` and
`storage`). 0.2.1 added `public/frankenphp-worker.php` to the owned files, and
every shop on 0.2.0 refused the whole package: "The package claims a path it
may not replace". The list in the new code does not matter — the old one
decides. So the worker loop went into `app/` and is entered from
`public/index.php`, which was always owned; `WorkerModeTest` freezes the list.
And the check that would have caught it: install the built package on a copy
running the PREVIOUS version before publishing it. Found 2026-10-22.

## Never call Artisan while the application boots

`ShopClock::reconcile()` runs in a provider's `boot()` and called
`Artisan::call('config:clear')`. That builds the console application THEN —
before the engine's providers have registered their commands — and the
process keeps that instance: the update's next step, `torgash:update-finish`,
answered "There are no commands defined in the torgash namespace", and the
first live shop's update to 0.2.2 rolled itself back. It passed every test and
the test copy only because neither had a time zone saved; the live shop had.
Delete the cached file instead (`app()->getCachedConfigPath()`), and test an
update on a copy whose SETTINGS look like the live shop's, not only its
version. Found 2026-10-22.

## A restore must not touch extensions it does not own

The backups restored with `pg_restore --clean`, which begins by dropping the
dump's extensions. On a real server `pg_trgm` belongs to `postgres`, the shop's
user may not drop it, and the single transaction was refused whole — the
rollback an update counts on never happened. The restore now goes by the
dump's table of contents without its EXTENSION entries (`-L`); the extension
is already there. `BackupRestoreTest` restores as a role that does not own it.
Found 2026-10-22.

## Rows read at boot are frozen under a worker

`torgash.blocks` read every enabled block (and the switched-off zones) in
`boot()` and handed them to each zone's listener. Under php-fpm boot is every
request; under FrankenPHP's worker mode it is once per WORKER — so on the live
shop a zone switched off, a block edited or added stayed on the storefront
until the workers restarted. A listener registered at boot may decide WHICH
hooks to listen on; what it draws is read when it is called (`ZoneBlocks`, a
per-request memo). `WorkerModeTest` edits a block and switches its zone off
between two requests of one worker. Found 2026-10-23.

Same day: the Redis check on Admin → Cache wrote `SET key ok EX 30`; phpredis's
`set()` takes the expiry as an options array and refused the fourth argument,
so a working Redis was reported as not answering. `SETEX` reads the same in
both clients.

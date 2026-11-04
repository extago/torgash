<?php

namespace Tests\Feature\Exim;

use App\Core\Content\SafeTemplate;
use Tests\TestCase;
use Torgash\Exim\Template\Expression;
use Torgash\Exim\Template\FeedTemplate;
use Torgash\Exim\Template\TemplateException;

/**
 * The template language (PLAN §68): Liquid's tags over the exchange's one
 * expression language — conditions, loops, variables, filters — and what
 * keeps it safe to hand to whoever edits a profile: no code, a budget, an
 * error that names its line.
 */
class TemplateLanguageTest extends TestCase
{
    protected function render(string $source, array $context, string $escape = SafeTemplate::XML): string
    {
        return (new FeedTemplate($source))->render($context, $escape);
    }

    public function test_conditions_read_the_expression_language(): void
    {
        $item = ['price' => 1200, 'old' => 1500, 'brand' => 'Apple', 'name' => 'iPhone 15 Pro Max', 'stock' => 0, 'tags' => ['new', 'hit']];
        $source = implode("\n", [
            '{% if item.old > item.price and item.brand == "apple" %}sale{% elsif item.old %}old{% else %}none{% endif %}',
            '{% unless item.stock %}out{% endunless %}',
            '{% if item.name contains "pro max" or item.tags contains "HIT" %}pro{% endif %}',
            '{% if not item.missing %}missing{% endif %}',
            '{% if contains(item.name, "mini") %}mini{% else %}big{% endif %}',
            '{% if item.price * 1.2 >= 1440 %}marked{% endif %}',
            '{% if empty(item.missing) and item.tags.0 == "new" %}first{% endif %}',
        ]);

        $this->assertSame("sale\nout\npro\nmissing\nbig\nmarked\nfirst", $this->render($source, ['item' => $item]));
    }

    public function test_loops_variables_captures_and_cases(): void
    {
        $source = implode("\n", [
            '{% for p in item.pictures %}',
            '<picture n="{{ forloop.index }}"{% if forloop.last %} last="1"{% endif %}>{{ p }}</picture>',
            '{% endfor %}',
            '{% for p in item.none %}never{% else %}no pictures{% endfor %}',
            '{% for i in (1..3) reversed %}{{ i }}{% endfor %}',
            '{% for t in item.tags limit: 2 offset: 1 %}{{ t }}{% unless forloop.last %},{% endunless %}{% endfor %}',
            '{% for t in item.tags %}{% if t == "c" %}{% break %}{% endif %}{% if t == "a" %}{% continue %}{% endif %}{{ t }}{% endfor %}',
            '{% assign model = item.name | replace: item.brand, "" | trim %}',
            '{% assign parts = item.set | split: "," %}',
            '<model>{{ model }}</model><n>{{ parts | size }}</n><first>{{ parts | first | trim }}</first>',
            '{% capture note %}{{ item.brand | upcase }} / {{ model }}{% endcapture %}',
            '<note>{{ note }}</note>',
            '{% case item.condition %}{% when "new" %}<c>Новое</c>{% when "used", "worn" %}<c>Б/у</c>{% else %}<c>?</c>{% endcase %}',
            '{% comment %}nothing {{ item.name }}{% endcomment %}',
            '{% raw %}{{ not a tag }}{% endraw %}',
        ]);

        $out = $this->render($source, ['item' => [
            'pictures' => ['a.jpg', 'b.jpg'], 'tags' => ['a', 'b', 'c', 'd'], 'name' => 'Apple iPhone 15',
            'brand' => 'Apple', 'set' => 'кабель, чехол ,коробка', 'condition' => 'used',
        ]]);

        $this->assertSame(implode("\n", [
            '<picture n="1">a.jpg</picture>',
            '<picture n="2" last="1">b.jpg</picture>',
            'no pictures',
            '321',
            'b,c',
            'b',
            '<model>iPhone 15</model><n>3</n><first>кабель</first>',
            '', // the capture's line: its tags were not alone on it, so the line stays (as in Liquid)
            '<note>APPLE / iPhone 15</note>',
            '<c>Б/у</c>',
            '{{ not a tag }}',
        ]), $out);
    }

    public function test_filters_and_escaping(): void
    {
        $item = [
            'name' => 'Квокфон «Про» & Co', 'text' => "<p>Hello   <b>big</b>\n world</p>", 'price' => 7199000, 'weight' => 171,
            'color' => '  титановый синий  ', 'params' => [['name' => 'Цвет', 'value' => 'синий'], ['name' => 'Память', 'value' => '1 ТБ']],
            'list' => ['b', 'a', 'b', '', 'c'], 'nothing' => '',
        ];
        $cases = [
            '{{ item.name }}' => 'Квокфон «Про» &amp; Co',
            '{{ item.name | raw }}' => 'Квокфон «Про» & Co',
            '{{ item.text | strip_tags | squish | truncate: 9 | cdata }}' => '<![CDATA[Hello big]]>',
            '{{ item.text | strip_html | truncatewords: 1 }}' => 'Hello',
            '{{ item.price | price }}' => '71990',
            '{{ item.weight | divide: 1000 }}' => '0.171',
            '{{ item.weight | divided_by: 1000 | plus: 1 }}' => '1.171',
            '{{ item.weight | times: 2 | minus: 42 | at_least: 400 }}' => '400',
            '{{ item.weight | modulo: 100 }}' => '71',
            '{{ 7.5 | ceil }}/{{ 7.5 | floor }}/{{ -3 | abs }}' => '8/7/3',
            '{{ item.price | number: 2 }}' => '7199000.00',
            '{{ item.color | trim | capitalize }}' => 'Титановый синий',
            '{{ item.color | strip | replace: "титановый ", "" | upcase }}' => 'СИНИЙ',
            '{{ item.color | lstrip | replace_first: "ти", "Ти" | rstrip }}' => 'Титановый синий',
            '{{ item.name | remove: "«Про» " | prepend: "> " | append: " <" }}' => '&gt; Квокфон &amp; Co &lt;',
            '{{ item.name | slice: 0, 7 }}/{{ item.name | slice: -2, 2 }}' => 'Квокфон/Co',
            '{{ item.list | sort | uniq | compact | join: "-" }}' => 'a-b-c',
            '{{ item.list | reverse | first }}/{{ item.list | last }}/{{ item.list | size }}' => 'c/c/5',
            '{{ item.params | map: "name" | join: ", " }}' => 'Цвет, Память',
            '{{ item.params | where: "name", "Память" | map: "value" | first }}' => '1 ТБ',
            '{{ item.params | sort: "name" | map: "name" | first }}' => 'Память',
            '{{ item.nothing | default: "—" }}/{{ item.missing | default: item.weight }}' => '—/171',
            '{{ "2026-11-04 10:30" | date: "d.m.Y" }}' => '04.11.2026',
            '{{ "a b&c" | url_encode }}' => 'a%20b%26c',
            '{{ item.name | escape }}' => 'Квокфон «Про» &amp; Co',
            "{{ item.text | strip_newlines | strip_tags | squish }}" => 'Hello big world',
        ];

        foreach ($cases as $source => $expected) {
            $this->assertSame($expected, $this->render($source, ['item' => $item]), $source);
        }

        // In a plain file nothing is escaped unless asked; in JSON the quotes are.
        $this->assertSame('a "b"', $this->render('{{ x }}', ['x' => 'a "b"'], SafeTemplate::RAW));
        $this->assertSame('a &quot;b&quot;', $this->render('{{ x | escape }}', ['x' => 'a "b"'], SafeTemplate::RAW));
        $this->assertSame('a \"b\"', $this->render('{{ x }}', ['x' => 'a "b"'], SafeTemplate::JSON));
    }

    public function test_whitespace_a_tag_alone_on_its_line_leaves_no_blank_line_and_dashes_trim(): void
    {
        $source = "<a>\n{% if yes %}\n  <b/>\n{% endif %}\n{% assign x = 1 %}\n<c>{{- x -}}</c>\n{%- if yes -%}\n<d/>\n{%- endif -%}\n</a>";

        $this->assertSame("<a>\n  <b/>\n<c>1</c><d/></a>", $this->render($source, ['yes' => true]));
    }

    public function test_variables_outlive_a_row_and_the_rows_stream(): void
    {
        $source = "<f>\n{% assign n = 0 %}\n{% for row in rows %}\n{% assign n = n | plus: 1 %}\n<r i=\"{{ forloop.index }}\" n=\"{{ n }}\">{{ row.sku }}</r>\n{% endfor %}\n<total>{{ n }}</total>\n</f>";
        $rows = (function () {
            yield ['sku' => 'A'];
            yield ['sku' => 'B'];
            yield ['sku' => 'C'];
        })();

        $out = implode('', iterator_to_array((new FeedTemplate($source))->stream([], $rows), false));

        $this->assertSame("<f>\n<r i=\"1\" n=\"1\">A</r>\n<r i=\"2\" n=\"2\">B</r>\n<r i=\"3\" n=\"3\">C</r>\n<total>3</total>\n</f>", $out);

        // The Mustache shape streams the same way, the row named item.
        $mustache = "{{#rows}}<r>{{ item.sku }}</r>{{/rows}}";
        $rows = (function () {
            yield ['sku' => 'A'];
            yield ['sku' => 'B'];
        })();
        $this->assertSame('<r>A</r><r>B</r>', implode('', iterator_to_array((new FeedTemplate($mustache))->stream([], $rows), false)));
    }

    public function test_paths_name_every_column_a_template_reads(): void
    {
        $template = new FeedTemplate('{% if item.old > item.price %}{% for p in item.pictures limit: item.max %}{{ item.name | default: item.sku }}{% endfor %}{% endif %}{% assign c = item.feature.color %}{% case item.kind %}{% when item.other %}{% endcase %}{{#item.params}}{{ name }}{{/item.params}}');

        $this->assertEqualsCanonicalizing(
            ['item.old', 'item.price', 'item.pictures', 'item.max', 'item.name', 'item.sku', 'item.feature.color', 'item.kind', 'item.other', 'item.params', 'name'],
            $template->paths(),
        );
    }

    public function test_a_mistake_names_its_line(): void
    {
        foreach ([
            "a\n{% if x %}\nb" => 'never closed',
            "{% if x %}\n{% endfor %}" => 'does not close',
            "{% endif %}" => 'closes nothing',
            "{% for x %}" => 'for name in list',
            "{% assign = 1 %}" => 'assign name = value',
            "{% if x == %}" => 'ends too early',
            "{% if system(\"dir\") %}" => 'no function',
            '{% shout %}' => 'no tag',
            '{{ item.x | shout }}' => 'no filter',
            "{% unless x %}\n{% elsif y %}" => 'inside',
            '{% raw %}never' => 'never closed',
            '{% case x %}{% when %}{% endcase %}' => 'names no value',
        ] as $source => $words) {
            try {
                new FeedTemplate($source);
                $this->fail('Parsed: '.$source);
            } catch (TemplateException $e) {
                $this->assertStringContainsString($words, $e->getMessage(), $source);
            }
        }
    }

    public function test_a_row_that_cannot_be_worked_out_says_its_line(): void
    {
        try {
            $this->render("<a/>\n{{ item.price / item.zero }}", ['item' => ['price' => 10, 'zero' => 0]]);
            $this->fail('Rendered a division by zero');
        } catch (TemplateException $e) {
            $this->assertStringContainsString('Line 2', $e->getMessage());
            $this->assertStringContainsString('Division by zero', $e->getMessage());
        }

        $this->assertSame('', $this->render('{{ item.weight / 1000 }}', ['item' => ['weight' => '']]), 'arithmetic over an empty value is empty');
        $this->assertSame('no', $this->render('{% if item.weight / 1000 > 1 %}yes{% else %}no{% endif %}', ['item' => ['weight' => '']]));
    }

    public function test_the_budget_stops_a_template_that_does_too_much(): void
    {
        try {
            $this->render('{% for i in (1..100000) %}{% endfor %}', []);
            $this->fail('A range of a hundred thousand');
        } catch (TemplateException $e) {
            $this->assertStringContainsString('range may count up to', $e->getMessage());
        }

        try {
            $this->render('{% for i in (1..5000) %}{% for j in (1..5000) %}x{% endfor %}{% endfor %}', []);
            $this->fail('Twenty-five million steps');
        } catch (TemplateException $e) {
            $this->assertStringContainsString('too much work', $e->getMessage());
        }

        $deep = str_repeat('{% if x %}', 40).str_repeat('{% endif %}', 40);
        try {
            new FeedTemplate($deep);
            $this->fail('Forty levels deep');
        } catch (TemplateException $e) {
            $this->assertStringContainsString('nested more than', $e->getMessage());
        }

        try {
            $this->render('{% break %}', []);
            $this->fail('A break outside a loop');
        } catch (TemplateException $e) {
            $this->assertStringContainsString('outside a loop', $e->getMessage());
        }
    }

    public function test_nothing_in_a_template_reaches_the_host(): void
    {
        foreach ([
            '{{ item.name | system }}', '{% if phpinfo() %}{% endif %}', '{{ shop.name | __call }}',
            '{% include "x" %}', '{% assign x = file_get_contents("/etc/passwd") %}',
        ] as $source) {
            try {
                new FeedTemplate($source);
                $this->fail('Parsed: '.$source);
            } catch (TemplateException) {
                $this->addToAssertionCount(1);
            }
        }

        // A path can only walk the arrays it was given: an object's method is no path.
        $this->assertSame('', $this->render('{{ shop.secret.value }}', ['shop' => ['secret' => new \ArrayObject(['value' => 'x'])]]));
        $this->assertSame('', $this->render('{{ shop.name.__call }}{{ shop.name.getRealPath }}', ['shop' => ['name' => 'x']]), 'a path walks arrays, never methods');
    }

    public function test_the_expression_language_alone(): void
    {
        $resolve = fn (string $path) => ['a' => 5, 'b' => 'Привет, мир', 'list' => ['x', 'y']][$path] ?? null;

        foreach ([
            'a * 2 + 1' => 11.0,
            '"1 299,50" + 0.5' => 1300.0,
            'b contains "МИР"' => true,
            'list contains "Y"' => true,
            'not (a > 10) and a <> 4' => true,
            'len(b)' => 11,
            'len(list)' => 2,
            'join(split("a,b,c", ","), "-")' => 'a-b-c',
            'first(list) & last(list)' => 'xy',
            'capitalize("иВАН")' => 'Иван',
            'starts_with(b, "привет") and ends_with(b, "мир")' => true,
            'default(missing, "d")' => 'd',
            'if(a = 5, "five", "other")' => 'five',
            'list[1]' => 'y',
            'empty' => '',
            'missing == nil' => true,
        ] as $text => $expected) {
            $this->assertSame($expected, Expression::parse($text)->evaluate($resolve), $text);
        }
    }

    public function test_an_avito_feed_is_written_in_it(): void
    {
        $source = <<<'TPL'
{% for item in rows %}
<Ad>
    <Id>{{ item.id }}</Id>
    {% assign model = item.name | remove: item.brand | trim %}
    <Vendor>{{ item.brand }}</Vendor><Model>{{ model }}</Model>
    {% assign memory = item.feature.memory | upcase %}
    {% case memory %}{% when "1 ТБ", "1 TB" %}{% assign memory = "1024 ГБ" %}{% when "2 ТБ", "2 TB" %}{% assign memory = "2048 ГБ" %}{% else %}{% assign memory = memory | replace: " GB", " ГБ" %}{% endcase %}
    <MemorySize>{{ memory }}</MemorySize>
    {% assign color = item.feature.color | remove: "«" | remove: "»" | trim | capitalize %}
    {% if color == "Титановый синий" %}{% assign color = "Черный" %}{% endif %}
    <Color>{{ color }}</Color>
    {% if item.feature.condition contains "как новый" %}
    <Condition>Как новый</Condition>
    {% else %}
    <Condition>Новое</Condition><BoxSealed>Да</BoxSealed>
    {% endif %}
    {% assign options = item.feature.set | split: "," %}
    {% if len(options) > 0 %}
    <Set>{% for o in options %}<Option>{{ o | trim }}</Option>{% endfor %}</Set>
    {% endif %}
    <Images>{% for p in item.pictures limit: 10 %}<Image url="{{ p }}"/>{% endfor %}</Images>
</Ad>
{% endfor %}
TPL;

        $rows = (function () {
            yield ['id' => 7, 'name' => 'Apple iPhone 15 Pro', 'brand' => 'Apple', 'pictures' => ['a.jpg', 'b.jpg'],
                'feature' => ['memory' => '1 tb', 'color' => '«титановый синий»', 'condition' => 'Как новый, 100%', 'set' => 'кабель, коробка']];
        })();
        $xml = implode('', iterator_to_array((new FeedTemplate($source))->stream([], $rows), false));
        $doc = simplexml_load_string('<Ads>'.$xml.'</Ads>');

        $this->assertNotFalse($doc, $xml);
        $ad = $doc->Ad[0];
        $this->assertSame('iPhone 15 Pro', (string) $ad->Model);
        $this->assertSame('1024 ГБ', (string) $ad->MemorySize);
        $this->assertSame('Черный', (string) $ad->Color);
        $this->assertSame('Как новый', (string) $ad->Condition);
        $this->assertCount(0, $ad->BoxSealed);
        $this->assertSame(['кабель', 'коробка'], array_map('strval', iterator_to_array($ad->Set->Option, false)));
        $this->assertCount(2, $ad->Images->Image);
    }
}

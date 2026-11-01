<?php

namespace Tests\Unit;

use App\Core\Content\SafeTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The admin's own templates (App\Core\Content\SafeTemplate): values, loops,
 * a value's block (`#`, when it is there) and its inverse (`^`, when not).
 */
class SafeTemplateTest extends TestCase
{
    protected const TOWN = '{{#order.city}}Town: {{ order.city }}{{/order.city}}{{^order.city}}No town (one click){{/order.city}}';

    public function test_a_value_block_and_its_inverse_say_one_thing_or_the_other(): void
    {
        $this->assertSame('Town: Kyiv', SafeTemplate::render(self::TOWN, ['order' => ['city' => 'Kyiv']], SafeTemplate::RAW));
        $this->assertSame('No town (one click)', SafeTemplate::render(self::TOWN, ['order' => ['city' => '']], SafeTemplate::RAW));
        $this->assertSame('No town (one click)', SafeTemplate::render(self::TOWN, ['order' => []], SafeTemplate::RAW), 'a value not there at all');
    }

    public function test_the_inverse_of_a_list_is_its_being_empty(): void
    {
        $template = '{{#order.items}}{{ item.sku }} {{/order.items}}{{^order.items}}nothing ordered{{/order.items}}';

        $this->assertSame('A B ', SafeTemplate::render($template, ['order' => ['items' => [['sku' => 'A'], ['sku' => 'B']]]], SafeTemplate::RAW));
        $this->assertSame('nothing ordered', SafeTemplate::render($template, ['order' => ['items' => []]], SafeTemplate::RAW));
    }

    public function test_an_inverse_inside_a_loop_reads_the_row(): void
    {
        $template = '{{#order.items}}{{ item.name }}{{^item.variation}} (one kind){{/item.variation}}; {{/order.items}}';

        $this->assertSame('Boots; Hat (one kind); ', SafeTemplate::render($template, ['order' => ['items' => [
            ['name' => 'Boots', 'variation' => 'black, 42'],
            ['name' => 'Hat', 'variation' => ''],
        ]]], SafeTemplate::RAW));
    }

    public function test_the_inverse_escapes_what_it_prints(): void
    {
        $this->assertSame('No email, ring O\"Brien', SafeTemplate::render('{{^order.email}}No email, ring {{ order.name }}{{/order.email}}', ['order' => ['name' => 'O"Brien']]));
    }

    public function test_a_value_goes_through_its_formats_in_turn(): void
    {
        $context = ['order' => ['phone' => '+38 (067) 123-45-67', 'city' => ' Kyiv ', 'total_decimal' => '1042.25', 'q' => 'a b&c']];

        $this->assertSame('+380671234567', SafeTemplate::render('{{ order.phone | phone }}', $context, SafeTemplate::RAW));
        $this->assertSame('380671234567', SafeTemplate::render('{{ order.phone|digits }}', $context, SafeTemplate::RAW));
        $this->assertSame('KYIV', SafeTemplate::render('{{ order.city | trim | upper }}', $context, SafeTemplate::RAW));
        $this->assertSame('1042', SafeTemplate::render('{{ order.total_decimal | int }}', $context, SafeTemplate::RAW));
        $this->assertSame('a%20b%26c', SafeTemplate::render('{{ order.q | url }}', $context, SafeTemplate::RAW));
        $this->assertSame(' Kyiv ', SafeTemplate::render('{{ order.city | nosuchthing }}', $context, SafeTemplate::RAW), 'an unknown format is left out');
        // Escaped after the format, so a JSON body stays a JSON body.
        $this->assertSame('o\"brien', SafeTemplate::render('{{ order.name | lower }}', ['order' => ['name' => 'O"Brien']]));
    }
}

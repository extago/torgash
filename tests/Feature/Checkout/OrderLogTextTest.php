<?php

namespace Tests\Feature\Checkout;

use Tests\TestCase;
use Torgash\Checkout\Models\OrderLog;

/**
 * An order's history is written by one person and read by another, often in
 * another language — so a line is a key and its parameters, translated when
 * READ. The bug this pins: "Status set to Открыт" on an English screen and
 * "Delivery: …" on a Russian one.
 */
class OrderLogTextTest extends TestCase
{
    public function test_a_line_reads_in_the_readers_language(): void
    {
        $line = new OrderLog([
            'description' => 'Status set to :status',
            'params' => ['status' => ['en' => 'Open', 'ru' => 'Открыт']],
        ]);

        app()->setLocale('en');
        $this->assertSame('Status set to Open', $line->text());

        app()->setLocale('ru');
        $this->assertSame('Статус: Открыт', $line->text());
    }

    public function test_a_declared_word_as_a_parameter_is_translated_too(): void
    {
        $line = new OrderLog([
            'description' => 'Manager: :from → :to',
            'params' => ['from' => OrderLog::word('unassigned'), 'to' => 'Olena'],
        ]);

        app()->setLocale('ru');
        $this->assertSame('Менеджер: не назначен → Olena', $line->text());
    }

    /** A line written before keys, that no shape recognised, still reads as it did. */
    public function test_an_old_finished_sentence_is_printed_as_it_was(): void
    {
        app()->setLocale('ru');

        $this->assertSame('Something a module wrote', (new OrderLog(['description' => 'Something a module wrote']))->text());
    }
}

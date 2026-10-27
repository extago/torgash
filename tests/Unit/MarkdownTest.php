<?php

namespace Tests\Unit;

use App\Core\Content\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * Reading a rich-text field back as text.
 *
 * A unit test with no application behind it, because the class has no
 * dependencies — and because these are the cases a hand-written walker gets
 * wrong: a nested list, a link with no text, a table nobody can convert, and
 * Cyrillic, which is where a missing encoding declaration turns into mojibake
 * that no error ever mentions.
 */
class MarkdownTest extends TestCase
{
    public function test_it_converts_the_tags_the_editor_can_produce(): void
    {
        $markdown = Markdown::fromHtml(
            '<h2>Specification</h2><p>A <strong>fast</strong> and <em>quiet</em> laptop.</p>'
            .'<blockquote>Two-year warranty</blockquote><hr><pre><code>echo 1;</code></pre>',
        );

        $this->assertStringContainsString('## Specification', $markdown);
        $this->assertStringContainsString('A **fast** and *quiet* laptop.', $markdown);
        $this->assertStringContainsString('> Two-year warranty', $markdown);
        $this->assertStringContainsString('---', $markdown);
        $this->assertStringContainsString("```\necho 1;\n```", $markdown);
    }

    public function test_a_list_inside_a_list_keeps_its_level(): void
    {
        $markdown = Markdown::fromHtml('<ul><li>Memory</li><li>Storage:<ol><li>SSD</li><li>HDD</li></ol></li></ul>');

        $this->assertStringContainsString('- Memory', $markdown);
        $this->assertStringContainsString('- Storage:', $markdown);
        // Numbered, and indented under the item it belongs to — a flat list
        // here would say the disk options are two more features.
        $this->assertMatchesRegularExpression('/\n\s+1\. SSD/', $markdown);
        $this->assertMatchesRegularExpression('/\n\s+2\. HDD/', $markdown);
    }

    public function test_links_and_images_keep_their_address(): void
    {
        $markdown = Markdown::fromHtml(
            '<p><a href="https://example.test/manual">Manual</a> and '
            .'<img src="/storage/1/chart.jpg" alt="Size chart" width="900"></p>',
        );

        $this->assertStringContainsString('[Manual](https://example.test/manual)', $markdown);
        $this->assertStringContainsString('![Size chart](/storage/1/chart.jpg)', $markdown);
    }

    /** A link with nothing inside it is still an address worth keeping. */
    public function test_a_link_with_no_text_becomes_the_bare_address(): void
    {
        $this->assertStringContainsString('<https://example.test/x>', Markdown::fromHtml('<p><a href="https://example.test/x"></a></p>'));
    }

    /**
     * A size chart is the reason: Markdown has no table the editor can make,
     * so the HTML one is passed through rather than flattened into a line of
     * numbers stuck together.
     */
    public function test_a_table_survives_as_html(): void
    {
        $markdown = Markdown::fromHtml('<p>Sizes:</p><table><tr><td>S</td><td>40</td></tr></table>');

        $this->assertStringContainsString('Sizes:', $markdown);
        $this->assertStringContainsString('<table>', $markdown);
        $this->assertStringContainsString('<td>40</td>', $markdown);
    }

    public function test_cyrillic_survives(): void
    {
        $this->assertSame('**Ноутбук** и мышь', Markdown::fromHtml('<p><strong>Ноутбук</strong> и мышь</p>'));
    }

    /** An old plain-text description is already what we want. */
    public function test_text_with_no_markup_is_returned_as_it_is(): void
    {
        $this->assertSame('Just words', Markdown::fromHtml('Just words'));
        $this->assertSame('', Markdown::fromHtml(null));
        $this->assertSame('', Markdown::fromHtml('   '));
    }

    /** Entities are for HTML; nobody wants to read &amp; in a text file. */
    public function test_entities_are_decoded(): void
    {
        $this->assertSame('Bolts & nuts', Markdown::fromHtml('<p>Bolts &amp; nuts</p>'));
    }
}

<?php

namespace Tests\Feature\Core;

use App\Core\Updates\ReleaseNotes;
use Tests\TestCase;

/**
 * One release text for two readers: GitHub shows it whole, English first and
 * Russian under a `---`; a shop shows the section in its admin's language,
 * drawn from Markdown — without whatever raw HTML the text may carry.
 */
class ReleaseNotesTest extends TestCase
{
    protected string $notes = "## What's new\n\n- CDEK delivery\n\n---\n\n## Что нового\n\n- Доставка СДЭК\n";

    public function test_each_admin_reads_its_own_language(): void
    {
        $this->assertStringContainsString('CDEK delivery', ReleaseNotes::section($this->notes, 'en'));
        $this->assertStringNotContainsString('СДЭК', ReleaseNotes::section($this->notes, 'en'));
        $this->assertStringContainsString('Доставка СДЭК', ReleaseNotes::section($this->notes, 'ru'));
        $this->assertStringContainsString('CDEK delivery', ReleaseNotes::section($this->notes, 'uk'), 'a language with no section reads the English');
        $this->assertSame('Plain text', ReleaseNotes::section('Plain text', 'ru'), 'a text with no sections is the whole text');
    }

    public function test_it_is_drawn_from_markdown_without_raw_html(): void
    {
        $html = (string) ReleaseNotes::html("## Fixed\n\n- **Backups**<script>alert(1)</script>", 'en');

        $this->assertStringContainsString('<h2>Fixed</h2>', $html);
        $this->assertStringContainsString('<strong>Backups</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}

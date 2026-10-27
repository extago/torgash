<?php

namespace App\Core\Assets;

use Illuminate\Console\Command;
use Throwable;

/** `torgash:styles` — build the stylesheet on this server, fetching the pinned builder first if asked (PLAN §45.2). */
class StylesCommand extends Command
{
    protected $signature = 'torgash:styles {--fetch : Download this platform\'s builder (checked against its pinned sha256) when none is here}';

    protected $description = 'Build the shop\'s stylesheet from the templates of core, themes and modules';

    public function handle(StyleBuilder $styles): int
    {
        try {
            if ($this->option('fetch') && $styles->binary() === null) {
                $this->line('Downloading the builder for '.$styles->platform().'…');
                $this->line('  '.$styles->fetch());
            }

            $built = $styles->build();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Stylesheet built: %s KB in %s s.', number_format($built['bytes'] / 1024, 0), $built['seconds']));

        return self::SUCCESS;
    }
}

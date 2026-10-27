<?php

namespace Torgash\Guard\Console;

use Illuminate\Console\Command;
use Torgash\Guard\Blocking\Blocks;
use Torgash\Guard\Models\Block;

/**
 * What the guard has decided about, from a shell.
 *
 * The companion to `guard:unblock`: somebody who cannot reach the admin needs
 * to see the list before they can name the address to release, and "release
 * everything" should not be the only thing they can do blind.
 */
class BlocksCommand extends Command
{
    protected $signature = 'guard:blocks {--all : Include the ones that have expired or been let back in}';

    protected $description = 'List the addresses the shop is blocking, or would be blocking.';

    public function handle(Blocks $blocks): int
    {
        $this->line('Mode: '.match ($blocks->mode()) {
            Blocks::OFF => 'off — nothing is recorded and nobody is refused',
            Blocks::ON => 'enforcing — blocked addresses are refused',
            default => 'watch — everything is recorded and nobody is refused',
        });

        $query = Block::query()->orderByDesc('updated_at');

        if (! $this->option('all')) {
            $query->active();
        }

        $rows = $query->limit(200)->get()->map(fn (Block $block) => [
            $block->ip,
            $block->mode,
            $block->reason,
            $block->strikes,
            $block->hits,
            $block->until?->toDateTimeString() ?? '—',
            $block->released_at ? 'released' : ($block->isActive() ? 'active' : 'expired'),
        ]);

        if ($rows->isEmpty()) {
            $this->info('Nothing is blocked.');

            return self::SUCCESS;
        }

        $this->table(['Address', 'Mode', 'Reason', 'Strikes', 'Hits', 'Until', 'State'], $rows);

        return self::SUCCESS;
    }
}

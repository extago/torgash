<?php

namespace Torgash\Guard\Console;

use App\Core\Settings\SettingsManager;
use Illuminate\Console\Command;
use Torgash\Guard\Blocking\Blocks;

/**
 * The way back in (PLAN §25.2/4, rule three).
 *
 * There is a Release button on the screen, and it is not the answer: the screen
 * lives in the admin, and the admin is behind the door this module can close.
 * Locking yourself out with one wrong number in a threshold is a five-second
 * mistake with a long tail, so the escape hatch has to be somewhere the block
 * cannot reach — which on every install means the shell the shop is deployed
 * from.
 *
 *     php artisan guard:unblock 203.0.113.9
 *     php artisan guard:unblock --all
 *     php artisan guard:unblock --all --watch
 *
 * `--watch` is the second half of the same emergency, and the reason the flag
 * exists rather than being a second command: an address let back in while the
 * rule that caught it is still enforcing gets caught again on the way to the
 * settings screen. It puts the whole module back into watch mode, so the shop
 * can reach its own admin and decide there.
 */
class UnblockCommand extends Command
{
    protected $signature = 'guard:unblock
        {address? : The address to let back in}
        {--all : Let every blocked address back in}
        {--watch : Also put blocking back into watch mode, so nothing is refused}';

    protected $description = 'Let a blocked address back in — the way out that does not need the admin panel.';

    public function handle(Blocks $blocks, SettingsManager $settings): int
    {
        $address = (string) $this->argument('address');

        if ($address === '' && ! $this->option('all')) {
            $this->error('Name an address, or pass --all.');

            return self::FAILURE;
        }

        if ($this->option('all')) {
            $this->info('Let back in: '.$blocks->releaseAll().' address(es).');
        } else {
            $this->info($blocks->release($address) > 0
                ? "{$address} is no longer blocked."
                : "{$address} was not blocked. Nothing to do.");
        }

        if ($this->option('watch')) {
            $settings->set('torgash.guard', 'blocking_mode', Blocks::WATCH);
            $blocks->flush();

            $this->info('Blocking is back in watch mode: it records what it would do and refuses nobody.');
        }

        return self::SUCCESS;
    }
}

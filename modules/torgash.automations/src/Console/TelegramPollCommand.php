<?php

namespace Torgash\Automations\Console;

use Illuminate\Console\Command;
use Torgash\Automations\Telegram\Bot;
use Torgash\Automations\Telegram\Listening;

/**
 * Hear what the bots were told — button presses, `/link`, `/id` — by asking
 * Telegram (PLAN §64.1). Started by the scheduler every minute and kept
 * asking for most of that minute, so a press is answered within a second or
 * two without the shop being reachable from the internet. With no bot
 * waiting to be heard (no enabled rule sends buttons) it ends at once.
 */
class TelegramPollCommand extends Command
{
    protected $signature = 'automations:telegram-poll {--once : Ask each bot once and stop} {--for=50 : Keep asking for this many seconds}';

    protected $description = 'Receive Telegram button presses by polling the Bot API';

    public function handle(): int
    {
        $until = microtime(true) + max(1, (int) $this->option('for'));
        $heard = 0;

        do {
            $bots = Listening::bots()->where('mode', Bot::POLL)->values();

            if ($bots->isEmpty()) {
                break;
            }

            // One bot may be held open ("long polling"); several are asked in turn.
            $wait = $this->option('once') || $bots->count() > 1 ? 0 : (int) min(25, max(0, $until - microtime(true)));

            foreach ($bots as $bot) {
                try {
                    $heard += Listening::ask($bot, $wait);
                } catch (\Throwable $e) {
                    // The network between here and Telegram drops; the next round asks again.
                    $this->warn($bot->mask($e->getMessage()));
                    sleep(2);
                }
            }

            if ($bots->count() > 1 && ! $this->option('once')) {
                sleep(2);
            }
        } while (! $this->option('once') && microtime(true) < $until);

        $this->info("Heard {$heard} update(s).");

        return self::SUCCESS;
    }
}

<?php

namespace Torgash\Automations\Telegram;

use Illuminate\Database\Eloquent\Collection;
use Torgash\Automations\Models\RuleAction;

/**
 * How presses reach the shop (PLAN §64.1) — two ways, per bot:
 *
 * - **poll** (the default, works anywhere — a shop on a laptop too): the
 *   scheduler starts `automations:telegram-poll` every minute and it keeps
 *   asking Telegram for the rest of that minute, so a press is heard within
 *   a second or two;
 * - **webhook**: Telegram calls the shop itself. Instant and costs nothing
 *   while nobody presses, but the shop's address must be reachable from the
 *   internet over https.
 *
 * Telegram allows one of the two at a time, so switching sets or removes the
 * webhook there — a bot left with a webhook answers nothing to a poll.
 */
class Listening
{
    public const UPDATES = ['message', 'callback_query'];

    /**
     * The bots somebody is waiting to hear from: those an ENABLED rule sends
     * buttons through. A rule switched off or deleted stops the asking.
     *
     * @return Collection<int, Bot>
     */
    public static function bots(): Collection
    {
        $hashes = [];

        foreach (RuleAction::where('type', 'telegram')->with('rule')->get() as $action) {
            $token = (string) $action->config('token', '');

            if ($token === '' || (array) $action->config('buttons', []) === []) {
                continue;
            }

            // Written down as soon as a rule has buttons — a staff member
            // links their account before the first message ever goes out.
            Bot::for($token);

            if ($action->rule?->enabled) {
                $hashes[] = Bot::hash($token);
            }
        }

        return $hashes === [] ? new Collection : Bot::whereIn('token_hash', array_unique($hashes))->orderBy('id')->get();
    }

    /** Can Telegram reach this shop — is its address public https? */
    public static function reachable(): bool
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($url, 'https://')
            && $host !== ''
            && ! preg_match('/(^localhost$|\.local$|\.test$|\.localhost$|^\d+\.\d+\.\d+\.\d+$)/i', $host);
    }

    /** @return string|null  what went wrong, in Telegram's words */
    public static function useWebhook(Bot $bot): ?string
    {
        $response = $bot->call('setWebhook', [
            'url' => route('automations.telegram.webhook', $bot->token_hash),
            'secret_token' => $bot->secret,
            'allowed_updates' => self::UPDATES,
        ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            return (string) $response->json('description') ?: 'HTTP '.$response->status();
        }

        $bot->update(['mode' => Bot::WEBHOOK]);

        return null;
    }

    /** @return string|null  what went wrong, in Telegram's words */
    public static function usePolling(Bot $bot): ?string
    {
        $response = $bot->call('deleteWebhook');

        if (! $response->successful() || $response->json('ok') !== true) {
            return (string) $response->json('description') ?: 'HTTP '.$response->status();
        }

        $bot->update(['mode' => Bot::POLL]);

        return null;
    }

    /**
     * Ask one bot once and act on what it has; `$wait` seconds Telegram may
     * hold the answer open for (its "long polling").
     *
     * @return int how many updates came
     */
    public static function ask(Bot $bot, int $wait = 0): int
    {
        $response = $bot->call('getUpdates', [
            'offset' => $bot->update_offset,
            'timeout' => $wait,
            'allowed_updates' => self::UPDATES,
        ], $wait + 10);

        $updates = (array) $response->json('result', []);
        $handler = app(Updates::class);

        foreach ($updates as $update) {
            // Read past it first: an update that blows up must not come back for ever.
            $bot->forceFill(['update_offset' => (int) ($update['update_id'] ?? 0) + 1])->save();

            try {
                $handler->handle($bot, (array) $update);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return count($updates);
    }
}

<?php

namespace Torgash\Automations\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Automations\Telegram\Bot;
use Torgash\Automations\Telegram\Updates;

/**
 * Telegram calling the shop about one bot (PLAN §64.1) — outside the `web`
 * group: no session, no CSRF token, nobody signed in.
 *
 * The address names the bot by the hash of its token and proves nothing; what
 * proves the caller is the secret Telegram was given with the webhook and
 * sends back in a header. Whoever pressed is then found and checked by
 * {@see Updates} exactly as when we do the asking.
 *
 * Always 200 once the caller is known: Telegram repeats what it gets an
 * error for, and an update that fails here would fail again.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $bot)
    {
        $row = Bot::where('token_hash', $bot)->where('mode', Bot::WEBHOOK)->first();

        if (! $row || ! hash_equals((string) $row->secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(404);
        }

        try {
            app(Updates::class)->handle($row, (array) $request->json()->all());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}

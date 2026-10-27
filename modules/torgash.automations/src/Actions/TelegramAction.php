<?php

namespace Torgash\Automations\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Template;

/**
 * A message to a Telegram chat — "a new order" on the manager's phone.
 *
 * It IS a webhook (one POST to the Bot API), and could be typed as one. It is
 * an action of its own for the one thing a webhook cannot do safely: the bot
 * token is part of the Bot API's URL, so as a webhook it would sit in the URL
 * field, be printed in the run log beside every message, and travel in an
 * exported template. Here it is a credential — never drawn back into the form,
 * kept when the box is left blank, masked in the log, and asked for by name
 * when a template is imported.
 *
 * The text is written the way a person writes a message, with the template's
 * {{ variables }}, and sent with `parse_mode=HTML` so <b> and <i> work; values
 * are HTML-escaped (Template::HTML), so a customer's name can never become
 * markup — nor break the message, which Telegram refuses outright when the
 * HTML does not parse.
 */
class TelegramAction implements Action
{
    /** Telegram refuses a longer message outright. */
    public const MAX_LENGTH = 4096;

    public static function label(): string
    {
        return 'Send a Telegram message';
    }

    public static function subjects(): array
    {
        return [];
    }

    public function run(RuleAction $action, Model $order, array $context, string $idempotencyKey): array
    {
        $token = trim((string) $action->config('token'));
        $chat = trim((string) $action->config('chat_id'));

        if ($token === '' || $chat === '') {
            return [
                'status' => RuleRun::FAILED,
                'summary' => __('The bot token or the chat id is not set.'),
                'detail' => [],
            ];
        }

        $text = mb_substr(trim(Template::render((string) $action->config('text', ''), $context, Template::HTML)), 0, self::MAX_LENGTH);

        // The token is in the path, so the log gets the address without it.
        $shownUrl = 'https://api.telegram.org/bot…/sendMessage';

        try {
            $response = Http::timeout((int) setting('torgash.automations', 'timeout') ?: 10)
                ->withoutRedirecting()
                ->asJson()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chat,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            $detail = [
                'url' => $shownUrl,
                'method' => 'POST',
                'request' => $text,
                'code' => $response->status(),
                'response' => mb_substr(str_replace($token, '…', $response->body()), 0, 2000),
            ];

            if ($response->successful() && $response->json('ok') === true) {
                return ['status' => RuleRun::SENT, 'summary' => __('Delivered to chat :chat', ['chat' => $chat]), 'detail' => $detail];
            }

            // Telegram explains itself in `description` ("chat not found",
            // "bot was blocked by the user") — that sentence is the fix.
            return [
                'status' => RuleRun::FAILED,
                'summary' => 'HTTP '.$response->status().': '.((string) $response->json('description') ?: __('Telegram refused the message')),
                'detail' => $detail,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => RuleRun::FAILED,
                'summary' => str_replace($token, '…', $e->getMessage()),
                'detail' => ['url' => $shownUrl, 'method' => 'POST', 'request' => $text],
            ];
        }
    }
}

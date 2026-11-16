<?php

namespace Torgash\Automations\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A bot the shop hears from (PLAN §64.1). A rule only ever WRITES through a
 * bot, with the token it keeps itself; buttons need the other direction, and
 * that needs one row per bot — however many rules share it: where its
 * presses come in, and how far the asking has read.
 *
 * Found by a hash of the token, never by the token; the token itself is kept
 * encrypted and is in no log, address or page.
 */
class Bot extends Model
{
    public const POLL = 'poll';

    public const WEBHOOK = 'webhook';

    protected $table = 'm_automations_telegram_bots';

    protected $guarded = [];

    protected $hidden = ['token', 'secret'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'update_offset' => 'int', 'heard_at' => 'datetime'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /** The bot behind this token, known from now on. */
    public static function for(string $token): self
    {
        $token = trim($token);

        return static::firstOrCreate(
            ['token_hash' => self::hash($token)],
            ['token' => $token, 'mode' => self::POLL, 'secret' => Str::random(48)],
        );
    }

    /**
     * One call of the Bot API. The token is in the address, so nothing made
     * from the response or an exception may be printed without `mask()`.
     *
     * @param  array<string, mixed>  $params
     */
    public function call(string $method, array $params = [], int $timeout = 10): Response
    {
        return Http::timeout($timeout)->connectTimeout(5)->withoutRedirecting()->asJson()
            ->post('https://api.telegram.org/bot'.$this->token.'/'.$method, $params);
    }

    public function mask(string $text): string
    {
        return str_replace((string) $this->token, '…', $text);
    }

    /** "@shop_bot", asked once and remembered. */
    public function name(): string
    {
        if (! $this->username) {
            try {
                $this->username = (string) $this->call('getMe')->json('result.username');
                $this->username !== '' ? $this->save() : null;
            } catch (\Throwable) {
                return '';
            }
        }

        return $this->username ? '@'.$this->username : '';
    }
}

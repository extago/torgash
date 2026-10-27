<?php

namespace Torgash\Automations\Codes;

/**
 * Ready fillings for the code-channel form: pick one and the fields are
 * written for you — nothing more than that. A preset is not a class of
 * channel and is never read again after the form is filled, so a provider
 * changing its API is fixed by editing the row, not by an update of this
 * module (PLAN §44.2/4: generic HTTP with presets, not a module per gateway).
 *
 * Placeholders: {{ phone }} (digits, no plus), {{ phone_plus }},
 * {{ email }}, {{ code }}, {{ text }} (the whole message in the customer's
 * language) and {{ secret.<name> }} for whatever the secrets box holds.
 *
 * Response rules, one per line, `dotted.path = value` (several values with
 * `|`); `success_when` must ALL hold, `invalid_when` / `unreachable_when`
 * fire on ANY line. A non-2xx answer is never a success.
 */
class Presets
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            // https://turbosms.ua/api.html — Bearer token; the sender must be a
            // name registered on the account (TAXI / MAGAZIN are the free test
            // ones). A batch answers 800/801 and each recipient 0 = OK.
            'turbosms' => [
                'name' => 'TurboSMS',
                'kinds' => ['phone'],
                'method' => 'POST',
                'url' => 'https://api.turbosms.ua/message/send.json',
                'headers' => 'Authorization: Bearer {{ secret.token }}',
                'body_format' => 'json',
                'body' => "{\n  \"recipients\": [\"{{ phone }}\"],\n  \"sms\": {\"sender\": \"MAGAZIN\", \"text\": \"{{ text }}\"}\n}",
                'success_when' => "response_code = 800|801\nresponse_result.0.response_code = 0",
                'invalid_when' => "response_code = 305\nresponse_result.0.response_code = 305|404",
                'unreachable_when' => "response_code = 406\nresponse_result.0.response_code = 406",
                'check_url' => 'https://api.turbosms.ua/user/balance.json',
                'secrets' => "token=",
                'countries' => 'UA',
            ],
            // https://core.telegram.org/gateway/api — the code arrives from the
            // "Verification Codes" account; Telegram charges for what it
            // delivers (free to your own number). It documents no error names
            // for a wrong or Telegram-less number and has no free "check the
            // key" request, so none are invented here: a refusal is logged
            // with Telegram's own words and the customer offered another way.
            'telegram_gateway' => [
                'name' => 'Telegram Gateway',
                'kinds' => ['phone'],
                'method' => 'POST',
                'url' => 'https://gatewayapi.telegram.org/sendVerificationMessage',
                'headers' => 'Authorization: Bearer {{ secret.token }}',
                'body_format' => 'json',
                'body' => "{\n  \"phone_number\": \"{{ phone_plus }}\",\n  \"code\": \"{{ code }}\",\n  \"ttl\": 600\n}",
                'success_when' => 'ok = true',
                'invalid_when' => '',
                'unreachable_when' => '',
                'check_url' => '',
                'secrets' => "token=",
                'countries' => '',
            ],
            // Anything else: a GET with the message in the address, the shape
            // most small SMS gateways document first.
            'blank' => [
                'name' => 'My SMS gateway',
                'kinds' => ['phone'],
                'method' => 'GET',
                'url' => 'https://sms.example.com/send?to={{ phone }}&text={{ text }}&key={{ secret.key }}',
                'headers' => '',
                'body_format' => 'none',
                'body' => '',
                'success_when' => '',
                'invalid_when' => '',
                'unreachable_when' => '',
                'check_url' => '',
                'secrets' => "key=",
                'countries' => '',
            ],
        ];
    }

    /** @return array<string, string> key => name, for the picker */
    public static function options(): array
    {
        return array_map(fn (array $preset) => $preset['name'], self::all());
    }
}

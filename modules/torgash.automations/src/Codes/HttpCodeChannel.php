<?php

namespace Torgash\Automations\Codes;

use App\Core\Content\SafeTemplate;
use App\Core\Verification\Channel;
use App\Core\Verification\SendResult;
use App\Models\VerificationCode;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Torgash\Automations\Actions\Ssrf;
use Torgash\Automations\Models\CodeChannel;

/**
 * A code delivered by an HTTP request the shop composed (PLAN §44.3) — the
 * webhook action's machinery run NOW rather than from the queue, because a
 * code is only useful with its answer: "that number cannot be right" has to
 * reach the customer while they are looking at the screen.
 *
 * Refused before anything is sent: a private or reserved host (Ssrf, the
 * same rule as every webhook), an address outside the channel's countries,
 * and a channel that has used up its day — sending costs money, and a public
 * "send me a code" form is exactly what gets pointed at premium numbers.
 * Secret values never leave this class in a message: they are masked out of
 * every error the log keeps.
 */
class HttpCodeChannel implements Channel
{
    protected const TIMEOUT = 10;

    public function __construct(protected CodeChannel $row) {}

    public function label(): string
    {
        return $this->row->name;
    }

    public function kinds(): array
    {
        return array_values((array) $this->row->kinds);
    }

    public function available(): bool
    {
        return $this->row->enabled && $this->sentToday() < max(1, $this->row->daily_limit);
    }

    public function supports(string $kind, string $address): bool
    {
        if (! in_array($kind, $this->kinds(), true)) {
            return false;
        }

        if ($kind !== 'phone') {
            return true;
        }

        // A landline cannot take an SMS; a number that is not one at all
        // cannot take anything.
        $phone = \App\Core\Support\Phones::parse($address);
        $countries = $this->row->countryList();

        return $phone !== null && $phone['mobile']
            && ($countries === [] || in_array($phone['country'], $countries, true));
    }

    /**
     * What the day has cost: every send except the ones the provider refused
     * outright (a wrong number, a country it does not serve) — those are not
     * charged, and a budget exists to cap what is.
     */
    public function sentToday(): int
    {
        return (int) VerificationCode::where('channel', $this->row->key())
            ->where('last_sent_at', '>=', now()->startOfDay())
            ->whereNotIn('status', [SendResult::INVALID_ADDRESS, SendResult::UNREACHABLE])
            ->sum('sends');
    }

    public function send(string $kind, string $address, array $message): SendResult
    {
        $context = $this->context($kind, $address, $message);
        $url = $this->renderUrl((string) $this->row->url, $context);

        if ($refusal = Ssrf::refuse($url)) {
            return SendResult::temporary($this->mask($refusal));
        }

        try {
            $response = $this->request($url, $context);
        } catch (Throwable $e) {
            return SendResult::temporary($this->mask(Str::limit($e->getMessage(), 250)));
        }

        return $this->judge($response);
    }

    /**
     * The channel's free request (a balance, a token check): proof that the
     * address and the key are right without spending a message.
     *
     * @return array{ok: bool, status: ?int, body: string}
     */
    public function check(): array
    {
        $url = $this->renderUrl((string) $this->row->check_url, $this->context('phone', '', ['code' => '', 'text' => '']));

        if ($url === '' || ($refusal = Ssrf::refuse($url))) {
            return ['ok' => false, 'status' => null, 'body' => $url === '' ? __('This channel has no check request.') : $this->mask($refusal)];
        }

        try {
            $response = Http::withHeaders($this->headers($this->context('phone', '', ['code' => '', 'text' => ''])))
                ->timeout(self::TIMEOUT)->withoutRedirecting()->acceptJson()->get($url);

            return ['ok' => $response->successful(), 'status' => $response->status(), 'body' => $this->mask(mb_substr($response->body(), 0, 1000))];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => $this->mask(Str::limit($e->getMessage(), 300))];
        }
    }

    protected function request(string $url, array $context): Response
    {
        $client = Http::withHeaders($this->headers($context))
            ->timeout(self::TIMEOUT)
            ->withoutRedirecting()
            ->acceptJson();
        $method = strtoupper((string) $this->row->method) ?: 'POST';

        return match ($this->row->body_format) {
            'json' => $client->withBody(SafeTemplate::render((string) $this->row->body, $context, SafeTemplate::JSON), 'application/json')->send($method, $url),
            'form' => $client->asForm()->send($method, $url, ['form_params' => $this->formFields($context)]),
            default => $client->send($method, $url),
        };
    }

    /**
     * What the provider said, read by the channel's own rules. A 2xx is
     * required for success but not enough when rules are written — gateways
     * answer 200 with "INVALID_PHONE" inside.
     */
    protected function judge(Response $response): SendResult
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $said = $this->mask('HTTP '.$response->status().': '.mb_substr($response->body(), 0, 240));

        if ($this->anyHolds((string) $this->row->invalid_when, $json)) {
            return SendResult::invalidAddress($said);
        }

        if ($this->anyHolds((string) $this->row->unreachable_when, $json)) {
            return SendResult::unreachable($said);
        }

        if ($response->successful() && $this->allHold((string) $this->row->success_when, $json)) {
            // The answer is kept even when it went: the provider's message id
            // is what "it never arrived" is traced by on their side.
            return SendResult::sent($said);
        }

        return SendResult::temporary($said);
    }

    /** @return list<array{0: string, 1: list<string>}> */
    protected function rules(string $text): array
    {
        $rules = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$path, $values] = array_map('trim', explode('=', $line, 2));

            if ($path !== '') {
                $rules[] = [$path, array_map('trim', explode('|', $values))];
            }
        }

        return $rules;
    }

    protected function holds(array $rule, array $json): bool
    {
        [$path, $values] = $rule;
        $actual = Arr::get($json, $path);
        $actual = is_bool($actual) ? ($actual ? 'true' : 'false') : (is_scalar($actual) ? (string) $actual : null);

        return $actual !== null && in_array($actual, $values, true);
    }

    protected function allHold(string $text, array $json): bool
    {
        foreach ($this->rules($text) as $rule) {
            if (! $this->holds($rule, $json)) {
                return false;
            }
        }

        return true;
    }

    protected function anyHolds(string $text, array $json): bool
    {
        foreach ($this->rules($text) as $rule) {
            if ($this->holds($rule, $json)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    protected function context(string $kind, string $address, array $message): array
    {
        $digits = $kind === 'phone' ? ltrim($address, '+') : '';

        return [
            'phone' => $digits,
            'phone_plus' => $digits === '' ? '' : '+'.$digits,
            'email' => $kind === 'email' ? $address : '',
            'code' => (string) ($message['code'] ?? ''),
            'text' => (string) ($message['text'] ?? ''),
            'secret' => (array) ($this->row->secrets ?? []),
        ];
    }

    /** Values going into an address are url-encoded; the address itself is not. */
    protected function renderUrl(string $template, array $context): string
    {
        $encoded = array_map(fn ($v) => is_array($v) ? array_map(fn ($s) => rawurlencode((string) $s), $v) : rawurlencode((string) $v), $context);

        return trim(SafeTemplate::render($template, $encoded, SafeTemplate::RAW));
    }

    /** @return array<string, string> */
    protected function headers(array $context): array
    {
        $headers = [];

        foreach (preg_split('/\R/', (string) $this->row->headers) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                if (trim($name) !== '') {
                    $headers[trim($name)] = trim(SafeTemplate::render($value, $context, SafeTemplate::RAW));
                }
            }
        }

        return $headers;
    }

    /** `name=value` lines of a form body. @return array<string, string> */
    protected function formFields(array $context): array
    {
        $fields = [];

        foreach (preg_split('/\R/', (string) $this->row->body) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $fields[trim($name)] = SafeTemplate::render(trim($value), $context, SafeTemplate::RAW);
            }
        }

        return $fields;
    }

    /** Every secret value, cut out of anything that may be shown or kept. */
    public function mask(string $text): string
    {
        foreach ((array) ($this->row->secrets ?? []) as $value) {
            if (is_string($value) && strlen($value) >= 4) {
                $text = str_replace([$value, rawurlencode($value)], '••••', $text);
            }
        }

        return $text;
    }
}

<?php

namespace Torgash\Automations\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Torgash\Automations\Models\RuleAction;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Template;

/**
 * POST what happened to a URL the shop owner chose.
 *
 * This one action is what makes a whole class of "integration modules"
 * unnecessary: analytics, a chat notification, Make/Zapier, a CRM lead — all of
 * them are this request with a different body.
 */
class WebhookAction implements Action
{
    /** Response bodies are kept for debugging, not archived: enough to read. */
    protected const KEEP_RESPONSE = 2000;

    public static function label(): string
    {
        return 'Send a webhook';
    }

    public static function subjects(): array
    {
        return [];
    }

    public function run(RuleAction $action, Model $order, array $context, string $idempotencyKey): array
    {
        $url = trim((string) $action->config('url'));
        $method = strtoupper((string) $action->config('method', 'POST'));
        $body = Template::render((string) $action->config('body', ''), $context);
        $headers = $this->headers($action, $context);

        if ($refusal = Ssrf::refuse($url)) {
            return [
                'status' => RuleRun::FAILED,
                'summary' => $refusal,
                'detail' => ['url' => $url, 'method' => $method],
            ];
        }

        $headers += [
            'Content-Type' => 'application/json',
            // A retried job must not create a second deal on the other side.
            // The key is fixed when the rule is triggered, so every attempt of
            // the same trigger carries the same one — which is exactly what a
            // receiver deduplicates on.
            'Idempotency-Key' => $idempotencyKey,
            'X-Torgash-Event' => (string) ($context['event'] ?? ''),
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) setting('torgash.automations', 'timeout') ?: 10)
                // Redirects are NOT followed: a receiver answering 302 to
                // 169.254.169.254 would walk straight past the check above,
                // and a webhook has no reason to be redirected anyway.
                ->withoutRedirecting()
                ->send($method, $url, $method === 'GET' ? [] : ['body' => $body]);

            $detail = [
                'url' => $url,
                'method' => $method,
                'request' => $body,
                'code' => $response->status(),
                'response' => mb_substr($response->body(), 0, self::KEEP_RESPONSE),
            ];

            // 2xx is delivery. Anything else is the receiver saying no, and it
            // is a failure the shop owner has to be able to see — a webhook
            // that "ran" but was refused is the failure mode nobody notices.
            // "Try later" (429) and the receiver's own errors (5xx) come round
            // again (RunRule); the Idempotency-Key keeps a retry one deal.
            return $response->successful()
                ? ['status' => RuleRun::SENT, 'summary' => 'HTTP '.$response->status(), 'detail' => $detail]
                : ['status' => RuleRun::FAILED, 'summary' => 'HTTP '.$response->status(), 'detail' => $detail,
                    'retry' => $response->status() === 429 || $response->serverError()];
        } catch (ConnectionException $e) {
            return [
                'status' => RuleRun::FAILED,
                'summary' => __('The receiver could not be reached: :error', ['error' => $e->getMessage()]),
                'detail' => ['url' => $url, 'method' => $method, 'request' => $body],
                'retry' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => RuleRun::FAILED,
                'summary' => $e->getMessage(),
                'detail' => ['url' => $url, 'method' => $method, 'request' => $body],
            ];
        }
    }

    /**
     * Headers are typed one per line, "Name: value" — the way everybody already
     * writes them, and the shape a vendor's docs give you to paste. Values go
     * through the template too, so a token can be composed the same way.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    protected function headers(RuleAction $action, array $context): array
    {
        $headers = [];

        foreach (preg_split('/\R/', (string) $action->config('headers', '')) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);

            if ($name !== '') {
                $headers[$name] = trim(Template::render($value, $context));
            }
        }

        return $headers;
    }
}

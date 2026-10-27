<?php

namespace App\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;

/**
 * Any letter of the registry (Letters::send) as a Mailable. Its context rides
 * the queue as model REFERENCES — a model is fetched fresh when the letter is
 * written, so an order changed between the click and the worker is written
 * as it is, and a deleted one sends nothing. Written in the reader's
 * language, set in the constructor because Mailable::send() reads it first.
 */
class EventMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300];

    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(public string $event, array $context, string $locale)
    {
        $this->payload = self::pack($context);
        $this->locale($locale);
    }

    public function build(): self
    {
        $context = self::unpack($this->payload);
        $letter = Letters::compose($this->event, $context, $this->locale ?? app()->getLocale());

        // Where "Reply" goes: the customer, for a letter to the shop about
        // them; the shop, for a letter a customer may want to answer.
        if ($letter['reply_to']) {
            $this->replyTo($letter['reply_to']);
        }

        return $this->subject($letter['subject'])->html($letter['html']);
    }

    /** What a test or a log may ask: which event this is. */
    public function event(): string
    {
        return $this->event;
    }

    /** @return array<string, mixed> */
    protected static function pack(array $context): array
    {
        return array_map(fn ($value) => $value instanceof Model
            ? ['__model' => $value::class, 'id' => $value->getKey()]
            : $value, $context);
    }

    /** @return array<string, mixed> */
    protected static function unpack(array $payload): array
    {
        return array_map(function ($value) {
            if (is_array($value) && isset($value['__model'], $value['id']) && is_subclass_of($value['__model'], Model::class)) {
                return $value['__model']::find($value['id']);
            }

            return $value;
        }, $payload);
    }
}

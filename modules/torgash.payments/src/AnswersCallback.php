<?php

namespace Torgash\Payments;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A gateway whose protocol dictates what the shop answers a callback WITH.
 *
 * Most do not care — any 200 stops the retries, and that is what the callback
 * controller sends. WayForPay does: it keeps redelivering until it receives a
 * signed `{"status": "accept"}` naming the order, for days. So a gateway that
 * needs one implements this beside `Gateway`, and the controller asks it
 * AFTER the event has been recorded and applied — the answer acknowledges
 * receipt, it never decides anything.
 *
 * Asked only for a callback whose signature verified: answering a forgery
 * with a valid signature would hand whoever sent it a sample of ours.
 */
interface AnswersCallback
{
    /**
     * Null means "the ordinary answer will do".
     *
     * @param  array<string, mixed>  $config
     */
    public function callbackResponse(Request $request, ?PaymentEvent $event, array $config): ?Response;
}

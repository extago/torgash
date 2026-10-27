<?php

namespace Torgash\Payments;

use RuntimeException;

/**
 * A gateway refused to start a payment, and knows why.
 *
 * The reason is MERCHANT-facing: "hold payments are allowed only for UAH" is a
 * sentence about how this shop configured its payment method, not something the
 * customer standing in front of the button can act on. So it goes into the
 * payment log and, on a shop that asked to see it, onto the screen — while the
 * customer is told, in every case, the one thing that is true for them: it did
 * not start, try again or call us.
 *
 * Throwing rather than returning null is what makes the difference visible at
 * all. `start()` returning null means "this gateway takes no money online",
 * which is a legitimate answer for a manual transfer; using the same value for
 * "the bank said no" is what made a refused invoice look like a button that
 * does nothing (§48 of the log).
 */
class PaymentFailed extends RuntimeException {}

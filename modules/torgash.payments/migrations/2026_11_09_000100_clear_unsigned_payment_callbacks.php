<?php

use Illuminate\Database\Migrations\Migration;
use Torgash\Payments\ForgedPayments;

/**
 * Takes away the payments unsigned callbacks made while they still could, and
 * blanks what such rows claimed (see ForgedPayments). On a shop nobody tried
 * this on, it only blanks the log rows of callbacks that failed their signature.
 *
 * `down()` does nothing: a forged payment is not something to put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orders = app(ForgedPayments::class)->clear();

        if ($orders !== []) {
            // An error, and the numbers in the text: the Errors screen records
            // from that level by default, and it is where the owner will look.
            logger()->error('Payments made by unsigned callbacks were removed. Check whether these orders were shipped: '.implode(', ', $orders));
        }
    }

    public function down(): void {}
};

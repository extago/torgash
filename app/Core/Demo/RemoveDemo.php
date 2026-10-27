<?php

namespace App\Core\Demo;

use App\Core\Tasks\TrackedJob;

/**
 * The System page's "Remove the demo data" (PLAN §55.3): every record the
 * pack made, through its own model — pictures and all, so a minute rather
 * than a request.
 */
class RemoveDemo extends TrackedJob
{
    public function label(): string
    {
        return 'Remove the demo data';
    }

    protected function run(): void
    {
        $this->message('Removing the demo data');

        $result = app(DemoData::class)->remove();

        $this->result($result, $result['failed'] > 0
            ? ':removed records removed, :failed could not be — see the log'
            : ':removed records removed', $result);
    }
}

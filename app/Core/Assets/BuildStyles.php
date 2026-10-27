<?php

namespace App\Core\Assets;

use App\Core\Tasks\TrackedJob;

/** The stylesheet built in the background — the System page's button and, later, every change of code (PLAN §45.2, §45.4). */
class BuildStyles extends TrackedJob
{
    public function label(): string
    {
        return 'Rebuild the stylesheet';
    }

    protected function run(): void
    {
        $this->message('Building the stylesheet');

        $built = app(StyleBuilder::class)->build();

        $this->result(['bytes' => $built['bytes']], 'Built in :seconds s', ['seconds' => $built['seconds']]);
    }
}

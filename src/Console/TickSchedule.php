<?php

/*
 * This file is part of ianm/ai-chatterbox.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace IanM\AIChatterbox\Console;

use IanM\AIChatterbox\Settings;
use Illuminate\Console\Scheduling\Event;

/**
 * Registers how often the AI Users tick runs. It fires every minute and the
 * command itself decides probabilistically whether to act, so activity reads as
 * organic rather than batched. The active-hours window is applied here so the
 * scheduler skips the run entirely outside it (the command re-checks too).
 */
class TickSchedule
{
    public function __construct(protected Settings $settings)
    {
    }

    public function __invoke(Event $event): void
    {
        $event->everyMinute()
            //->withoutOverlapping()
            ->between($this->settings->activeStart(), $this->settings->activeEnd());
    }
}

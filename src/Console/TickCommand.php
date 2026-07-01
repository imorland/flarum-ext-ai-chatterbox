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

use IanM\AIChatterbox\BotUserManager;
use IanM\AIChatterbox\Jobs\GenerateDiscussionJob;
use IanM\AIChatterbox\Jobs\GenerateReplyJob;
use IanM\AIChatterbox\Jobs\LikePostJob;
use IanM\AIChatterbox\NotificationResponder;
use IanM\AIChatterbox\Settings;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Queue;

/**
 * Runs every minute (see {@see TickSchedule}). Decides whether the AI users
 * should act this minute and, if so, enqueues one action job per "action" rolled
 * from the configured frequency. All OpenAI calls and writes happen in the jobs,
 * keeping the tick fast and each action independently retryable.
 */
class TickCommand extends Command
{
    /**
     * The tick runs once a minute, so new-content jobs are dispatched with a random
     * delay across this window. This scatters them through the minute instead of
     * firing them all at the :00 boundary, which is the main thing that makes
     * scheduler-driven activity look robotic.
     */
    protected const SPREAD_SECONDS = 59;

    /**
     * @var string
     */
    protected $signature = 'ai-users:tick';

    /**
     * @var string
     */
    protected $description = 'Decide whether AI users should act now and enqueue activity jobs.';

    public function __construct(
        protected Settings $settings,
        protected BotUserManager $bots,
        protected Queue $queue,
        protected NotificationResponder $responder
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!$this->settings->isOperable()) {
            $this->info('AI Chatterbox is disabled or not fully configured — nothing to do.');

            return Command::SUCCESS;
        }

        // Backstop: the scheduler applies between(), but re-check at run time.
        if (!$this->settings->withinActiveHours()) {
            $this->info('Outside the configured active hours — nothing to do.');

            return Command::SUCCESS;
        }

        $this->bots->ensure();

        // PRIMARY behaviour: respond to notifications first, uncapped by frequency.
        // The responder reads each bot's unread notifications, enqueues directed
        // replies for mentions, and marks everything it handled read.
        $responses = $this->responder->run();

        // SECONDARY: create new content (discussions / extra replies / likes) on top,
        // gated by the configured frequency and activity weights. The number of
        // actions this minute is drawn from a Poisson distribution (so arrivals
        // cluster and gap like real activity rather than ticking once a minute), and
        // each job is dispatched with a random delay across the window so it never
        // lands on the :00 minute boundary in a synchronous burst.
        $newContent = 0;
        $actions = $this->rollActionCount();
        $weights = $this->typeWeights();

        if ($actions > 0 && !empty($weights)) {
            for ($i = 0; $i < $actions; $i++) {
                $type = $this->weightedPick($weights);

                $this->queue->later(mt_rand(0, self::SPREAD_SECONDS), $this->jobFor($type));
                $newContent++;
            }
        }

        $enqueued = $responses + $newContent;

        if ($enqueued === 0) {
            $this->info('Nothing to do this tick.');
        } else {
            $this->info("Enqueued {$responses} notification response(s) and {$newContent} new action(s).");
        }

        return Command::SUCCESS;
    }

    /**
     * How many new-content actions to start this minute, drawn from a Poisson
     * distribution whose mean is the configured actions-per-hour expressed per
     * minute. Poisson models independent random arrivals, so most minutes produce
     * zero or one action, with the occasional natural cluster of two or three —
     * the ebb-and-flow of real posting rather than a fixed per-minute cadence.
     */
    protected function rollActionCount(): int
    {
        $mean = $this->settings->frequency() / 60;

        if ($mean <= 0) {
            return 0;
        }

        return $this->poisson($mean);
    }

    /**
     * Draw a sample from a Poisson distribution with the given mean, using Knuth's
     * algorithm. Fine for the small means involved here (a few actions per hour).
     */
    protected function poisson(float $mean): int
    {
        $l = exp(-$mean);
        $k = 0;
        $p = 1.0;

        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $l);

        return $k - 1;
    }

    /**
     * Enabled activity types mapped to their relative selection weight. A type is
     * included only if both its enable toggle is on and its weight is > 0.
     *
     * @return array<string, int>
     */
    protected function typeWeights(): array
    {
        $enabled = [
            'discussion' => $this->settings->discussionsEnabled(),
            'reply' => $this->settings->repliesEnabled(),
            'like' => $this->settings->likesEnabled(),
        ];

        $weights = [];

        foreach ($enabled as $type => $isEnabled) {
            $weight = $isEnabled ? $this->settings->weight($type) : 0;

            if ($weight > 0) {
                $weights[$type] = $weight;
            }
        }

        return $weights;
    }

    /**
     * Pick a type at random in proportion to its weight.
     *
     * @param array<string, int> $weights
     */
    protected function weightedPick(array $weights): string
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, $total);

        $cumulative = 0;
        foreach ($weights as $type => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return $type;
            }
        }

        // Unreachable given $roll <= $total, but satisfies the return type.
        return array_key_first($weights);
    }

    protected function jobFor(string $type): object
    {
        return match ($type) {
            'discussion' => new GenerateDiscussionJob(),
            'reply' => new GenerateReplyJob(),
            'like' => new LikePostJob(),
            default => throw new \InvalidArgumentException("Unknown activity type: {$type}"),
        };
    }
}

<?php

/*
 * This file is part of ianm/ai-chatterbox.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace IanM\AIChatterbox\Tests\unit;

use IanM\AIChatterbox\Console\TickCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PoissonTest extends TestCase
{
    protected function poisson(float $mean): int
    {
        $command = (new \ReflectionClass(TickCommand::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(TickCommand::class, 'poisson');

        return $method->invoke($command, $mean);
    }

    public function test_always_non_negative_and_integer(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertGreaterThanOrEqual(0, $this->poisson(0.1));
        }
    }

    public function test_empirical_mean_matches_requested_mean(): void
    {
        $mean = 0.5; // ~30 actions/hour expressed per minute
        $n = 20000;
        $sum = 0;

        for ($i = 0; $i < $n; $i++) {
            $sum += $this->poisson($mean);
        }

        $observed = $sum / $n;

        // Generous tolerance for randomness; Poisson variance == mean.
        $this->assertGreaterThan($mean - 0.05, $observed);
        $this->assertLessThan($mean + 0.05, $observed);
    }

    public function test_small_mean_mostly_zero_with_occasional_clusters(): void
    {
        // With a small mean, most minutes should be 0, but values >1 must occur —
        // that's the natural clustering we want.
        $counts = [];
        for ($i = 0; $i < 5000; $i++) {
            $k = $this->poisson(0.25);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }

        $this->assertGreaterThan($counts[1] ?? 0, $counts[0], 'zeros should dominate');
        $this->assertArrayHasKey(2, $counts, 'occasional clusters of 2 should appear');
    }
}

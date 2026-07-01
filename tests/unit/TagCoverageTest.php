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

use IanM\AIChatterbox\Jobs\GenerateDiscussionJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the scarcity-weighted tag picker that keeps discussion coverage even and
 * lets freshly-added tags (zero recent discussions) get used promptly.
 */
class TagCoverageTest extends TestCase
{
    /**
     * @param array<int, int> $counts
     */
    protected function pick(array $counts): int
    {
        $job = (new ReflectionClass(GenerateDiscussionJob::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(GenerateDiscussionJob::class, 'weightByScarcity');

        return $method->invoke($job, $counts);
    }

    public function test_always_in_range(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertContains($this->pick([5, 0, 3]), [0, 1, 2]);
        }
    }

    public function test_single_item_always_chosen(): void
    {
        $this->assertSame(0, $this->pick([12]));
    }

    public function test_empty_returns_zero(): void
    {
        $this->assertSame(0, $this->pick([]));
    }

    public function test_zero_count_tag_is_favoured_over_saturated_ones(): void
    {
        // Index 2 has 0 recent discussions (a newly added tag); the others are busy.
        // It should be picked far more often than its 1/3 uniform share.
        $counts = [17, 10, 0];
        $picks = [0, 0, 0];
        $n = 3000;

        for ($i = 0; $i < $n; $i++) {
            $picks[$this->pick($counts)]++;
        }

        $newTagShare = $picks[2] / $n;

        // Weights: 1/18, 1/11, 1/1 → the zero-count tag dominates (~88%).
        $this->assertGreaterThan(0.75, $newTagShare, 'A brand-new (0-count) tag should dominate the spontaneous pick.');
    }

    public function test_equal_counts_are_roughly_uniform(): void
    {
        $counts = [4, 4, 4, 4];
        $picks = [0, 0, 0, 0];
        $n = 4000;

        for ($i = 0; $i < $n; $i++) {
            $picks[$this->pick($counts)]++;
        }

        foreach ($picks as $share) {
            $this->assertGreaterThan(0.18, $share / $n);
            $this->assertLessThan(0.32, $share / $n);
        }
    }
}

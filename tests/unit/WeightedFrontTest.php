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

use Flarum\Discussion\Discussion;
use IanM\AIChatterbox\Jobs\GenerateReplyJob;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the front-weighted pick used to favour the longest-waiting unanswered
 * thread while keeping every candidate possible.
 */
class WeightedFrontTest extends TestCase
{
    /**
     * @param int[] $ids ordered front-to-back (front = highest priority)
     */
    protected function pickId(array $ids): int
    {
        $items = new Collection(array_map(function (int $id) {
            $d = new Discussion();
            $d->id = $id;

            return $d;
        }, $ids));

        $job = (new ReflectionClass(GenerateReplyJob::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(GenerateReplyJob::class, 'pickWeightedToFront');

        return (int) $method->invoke($job, $items)->id;
    }

    public function test_single_item_always_returned(): void
    {
        $this->assertSame(7, $this->pickId([7]));
    }

    public function test_always_returns_a_member_of_the_list(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertContains($this->pickId([1, 2, 3, 4]), [1, 2, 3, 4]);
        }
    }

    public function test_front_item_wins_most_often_but_not_always(): void
    {
        // Front (id=1) has the highest weight; back (id=4) the lowest, but non-zero.
        $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $n = 4000;

        for ($i = 0; $i < $n; $i++) {
            $counts[$this->pickId([1, 2, 3, 4])]++;
        }

        // Weights 4:3:2:1 → front ~40%, then descending, back ~10%.
        $this->assertGreaterThan($counts[2], $counts[1], 'front beats second');
        $this->assertGreaterThan($counts[3], $counts[2], 'second beats third');
        $this->assertGreaterThan($counts[4], $counts[3], 'third beats back');
        $this->assertGreaterThan(0, $counts[4], 'back still possible');
        // Front should be roughly 40% (allow slack).
        $this->assertGreaterThan(0.30, $counts[1] / $n);
        $this->assertLessThan(0.50, $counts[1] / $n);
    }
}

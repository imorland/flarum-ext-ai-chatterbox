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

use IanM\AIChatterbox\Jobs\GenerateReplyJob;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the per-discussion autonomous-reply slot limiter: up to 2 autonomous bot
 * replies per discussion within a window, the 3rd refused.
 */
class ReplySlotTest extends TestCase
{
    protected function job(): GenerateReplyJob
    {
        return (new ReflectionClass(GenerateReplyJob::class))->newInstanceWithoutConstructor();
    }

    protected function claim(GenerateReplyJob $job, Repository $cache, int $discussionId, int $max): bool
    {
        $method = new ReflectionMethod(GenerateReplyJob::class, 'claimAutonomousSlot');

        return $method->invoke($job, $cache, $discussionId, $max);
    }

    public function test_allows_up_to_the_limit_then_refuses(): void
    {
        $job = $this->job();
        $cache = new Repository(new ArrayStore());

        // With a limit of 2: two allowed, then refused.
        $this->assertTrue($this->claim($job, $cache, 42, 2), 'first autonomous reply allowed');
        $this->assertTrue($this->claim($job, $cache, 42, 2), 'second autonomous reply allowed');
        $this->assertFalse($this->claim($job, $cache, 42, 2), 'third refused');
        $this->assertFalse($this->claim($job, $cache, 42, 2), 'still refused');
    }

    public function test_respects_a_higher_limit(): void
    {
        $job = $this->job();
        $cache = new Repository(new ArrayStore());

        for ($i = 0; $i < 8; $i++) {
            $this->assertTrue($this->claim($job, $cache, 7, 8), "claim {$i} under limit 8");
        }
        $this->assertFalse($this->claim($job, $cache, 7, 8), 'ninth refused at limit 8');
    }

    public function test_separate_discussions_have_independent_allowances(): void
    {
        $job = $this->job();
        $cache = new Repository(new ArrayStore());

        $this->assertTrue($this->claim($job, $cache, 1, 2));
        $this->assertTrue($this->claim($job, $cache, 1, 2));
        // A different discussion still has its full allowance.
        $this->assertTrue($this->claim($job, $cache, 2, 2));
        $this->assertTrue($this->claim($job, $cache, 2, 2));
        $this->assertFalse($this->claim($job, $cache, 2, 2));
    }
}

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

class WeightedPickTest extends TestCase
{
    /**
     * Invoke the protected weightedPick() without constructing the command (its
     * constructor needs container services); the method uses no instance state.
     *
     * @param array<string, int> $weights
     */
    protected function pick(array $weights): string
    {
        $command = (new \ReflectionClass(TickCommand::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(TickCommand::class, 'weightedPick');

        return $method->invoke($command, $weights);
    }

    public function test_only_returns_keys_from_the_weight_map(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertContains($this->pick(['reply' => 3, 'discussion' => 1]), ['reply', 'discussion']);
        }
    }

    public function test_a_single_type_is_always_chosen(): void
    {
        $this->assertSame('reply', $this->pick(['reply' => 5]));
    }

    public function test_distribution_roughly_matches_weights(): void
    {
        $counts = ['discussion' => 0, 'reply' => 0];
        $n = 4000;

        for ($i = 0; $i < $n; $i++) {
            $counts[$this->pick(['discussion' => 1, 'reply' => 3])]++;
        }

        // Expect ~25% discussion / ~75% reply. Allow generous slack for randomness.
        $replyShare = $counts['reply'] / $n;
        $this->assertGreaterThan(0.68, $replyShare);
        $this->assertLessThan(0.82, $replyShare);
    }
}

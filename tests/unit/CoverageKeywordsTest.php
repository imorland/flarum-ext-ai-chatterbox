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

use IanM\AIChatterbox\BotUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the cheap (no-API) keyword tokenisation used to decide whether a persona's
 * interests already cover an enabled tag, which drives self-healing of new tags.
 */
class CoverageKeywordsTest extends TestCase
{
    /**
     * @return string[]
     */
    protected function keywords(string $text): array
    {
        $mgr = (new ReflectionClass(BotUserManager::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(BotUserManager::class, 'keywords');

        return $method->invoke($mgr, $text);
    }

    protected function overlaps(string $a, string $b): bool
    {
        return ! empty(array_intersect($this->keywords($a), $this->keywords($b)));
    }

    public function test_drops_short_generic_and_numeric_tokens(): void
    {
        $words = $this->keywords('The General discussion section 2026 chat');

        // 'the','general','section','chat' are stopped; '2026' is numeric; short words gone.
        $this->assertSame([], $words);
    }

    public function test_keeps_meaningful_words(): void
    {
        $words = $this->keywords('Technology and gadgets');

        $this->assertContains('technology', $words);
        $this->assertContains('gadgets', $words);
        $this->assertNotContains('and', $words);
    }

    public function test_persona_covering_a_tag_overlaps(): void
    {
        // A technology-interested persona covers a Technology tag.
        $this->assertTrue($this->overlaps(
            'Technology — the latest gadgets and computing',
            'Technology, smart home devices, indie video games'
        ));
    }

    public function test_unrelated_persona_does_not_overlap(): void
    {
        $this->assertFalse($this->overlaps(
            'Coffee corner — everything about coffee and espresso',
            'Politics, classic literature, birdwatching'
        ));
    }

    public function test_overlap_is_case_insensitive(): void
    {
        $this->assertTrue($this->overlaps('GARDENING tips', 'love gardening and plants'));
    }
}

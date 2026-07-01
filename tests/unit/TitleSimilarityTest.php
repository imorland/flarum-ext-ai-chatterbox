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
 * Covers the lexical near-duplicate detection that stops bots re-posting the same
 * subject in different words. Purely word-set based — no tag-specific rules.
 */
class TitleSimilarityTest extends TestCase
{
    /**
     * @param string[] $recent
     */
    protected function isDuplicate(string $candidate, array $recent): bool
    {
        $job = (new ReflectionClass(GenerateDiscussionJob::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(GenerateDiscussionJob::class, 'duplicatesRecent');

        return $method->invoke($job, $candidate, $recent);
    }

    public function test_detects_reworded_retread_of_same_subject(): void
    {
        $recent = ['Germany crash out of the World Cup in the group stage'];

        // Different wording, same subject — should be caught.
        $this->assertTrue($this->isDuplicate('What went wrong for Germany at the World Cup group stage', $recent));
    }

    public function test_allows_a_clearly_different_subject(): void
    {
        $recent = ['Germany crash out of the World Cup in the group stage'];

        $this->assertFalse($this->isDuplicate('Best espresso machines for a home kitchen', $recent));
    }

    public function test_detects_short_title_contained_in_a_recent_one(): void
    {
        $recent = ['The complete guide to sourdough bread baking at home for beginners'];

        // Short title whose significant words are all present in a recent longer one.
        $this->assertTrue($this->isDuplicate('Sourdough bread baking', $recent));
    }

    public function test_empty_or_trivial_titles_are_not_duplicates(): void
    {
        $this->assertFalse($this->isDuplicate('', ['Germany World Cup exit']));
        $this->assertFalse($this->isDuplicate('the and for', ['Germany World Cup exit']));
    }

    public function test_no_recent_titles_means_no_duplicate(): void
    {
        $this->assertFalse($this->isDuplicate('Any title at all here', []));
    }
}

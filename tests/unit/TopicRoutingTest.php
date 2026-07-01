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

use IanM\AIChatterbox\FeedReader;
use PHPUnit\Framework\TestCase;

/**
 * Covers the keyword-based shortlist that surfaces a diverse set of candidate news
 * items for the model to route. Keyword matching here only widens the candidate set
 * (the model makes the final tag decision), so the contract is: every tag with a
 * keyword match gets a contender, and breadth is topped up from the wider pool.
 */
class TopicRoutingTest extends TestCase
{
    /**
     * A FeedReader whose item pool is fixed (no network/cache).
     *
     * @param array<int, array{title: string, summary: string, link: string}> $items
     */
    protected function reader(array $items): FeedReader
    {
        return new class($items) extends FeedReader {
            /** @var array<int, array{title: string, summary: string, link: string}> */
            private array $fixed;

            public function __construct(array $items)
            {
                $this->fixed = $items;
            }

            public function allItems(): array
            {
                return $this->fixed;
            }
        };
    }

    /**
     * @return array{title: string, summary: string, link: string}
     */
    protected function item(string $title, string $summary = ''): array
    {
        return ['title' => $title, 'summary' => $summary, 'link' => 'https://example.test/'.md5($title)];
    }

    public function test_shortlist_includes_a_contender_for_each_matching_tag(): void
    {
        $reader = $this->reader([
            $this->item('England crash out of the World Cup after penalties', 'football tournament'),
            $this->item('New espresso brewing technique sweeps cafes', 'coffee beans roasting'),
            $this->item('Stock markets rally on rate news', 'finance economy'),
        ]);

        $tags = [
            10 => 'World Cup the football tournament',
            20 => 'Coffee corner everything about coffee and espresso',
        ];

        $titles = array_column($reader->shortlistItems($tags, 12), 'title');

        // The football and coffee stories should each be surfaced for their tag.
        $this->assertContains('England crash out of the World Cup after penalties', $titles);
        $this->assertContains('New espresso brewing technique sweeps cafes', $titles);
    }

    public function test_shortlist_respects_the_limit(): void
    {
        $items = [];
        for ($i = 0; $i < 30; $i++) {
            $items[] = $this->item("Story number {$i} about something", "summary {$i}");
        }

        $reader = $this->reader($items);

        $this->assertCount(5, $reader->shortlistItems([10 => 'News current affairs'], 5));
    }

    public function test_shortlist_is_empty_with_no_items(): void
    {
        $this->assertSame([], $this->reader([])->shortlistItems([10 => 'World Cup football']));
    }

    public function test_shortlist_tops_up_with_breadth_when_no_keyword_matches(): void
    {
        // Tag keywords match nothing, but we still want candidates for the model.
        $reader = $this->reader([
            $this->item('Volcano erupts in remote region', 'geology'),
            $this->item('Ancient manuscript discovered', 'history'),
        ]);

        $shortlist = $reader->shortlistItems([10 => 'Cryptocurrency blockchain trading'], 12);

        $this->assertNotEmpty($shortlist);
    }
}

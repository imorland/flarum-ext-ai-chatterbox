<?php

/*
 * This file is part of ianm/ai-chatterbox.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace IanM\AIChatterbox;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Fetches and parses the configured RSS/Atom feeds so the bots have fresh,
 * real-world topics to talk about. Results are cached so we don't hit the feeds
 * on every job, and every failure path returns an empty list so callers can fall
 * back to tag-only generation.
 */
class FeedReader
{
    /**
     * How long (seconds) to cache a feed's parsed items.
     */
    protected const CACHE_TTL = 900; // 15 minutes

    /**
     * Network timeout (seconds) for fetching a single feed.
     */
    protected const FETCH_TIMEOUT = 8;

    /**
     * Cap on how much of an item's summary to keep (characters).
     */
    protected const SUMMARY_LIMIT = 400;

    public function __construct(
        protected Settings $settings,
        protected Cache $cache
    ) {
    }

    /**
     * All recent items pooled across every configured feed.
     *
     * @return array<int, array{title: string, summary: string, link: string}>
     */
    public function allItems(): array
    {
        $items = [];

        foreach ($this->settings->feedUrls() as $url) {
            foreach ($this->itemsFor($url) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A random recent item across all configured feeds, or null if none are
     * available (no feeds configured, fetch failed, or all empty).
     *
     * @return array{title: string, summary: string, link: string}|null
     */
    public function randomItem(): ?array
    {
        $items = $this->allItems();

        if (empty($items)) {
            return null;
        }

        return $items[array_rand($items)];
    }

    /**
     * Build a small, diverse shortlist of candidate news items for the model to
     * route. We can't send the whole pool (hundreds of items) to the model, so we
     * use cheap keyword scoring only to *surface variety*: the best-scoring item for
     * each tag (so every enabled tag has a contender) plus a few random items for
     * breadth. The model makes the actual tag decision, so loose keyword matches here
     * are harmless — they just widen the candidate set.
     *
     * @param array<int, string> $tagText tag id => "name description"
     * @param int                $limit   max items to return
     * @return array<int, array{title: string, summary: string, link: string}>
     */
    public function shortlistItems(array $tagText, int $limit = 12): array
    {
        $items = $this->allItems();

        if (empty($items)) {
            return [];
        }

        shuffle($items);

        $picked = [];
        $seen = [];

        $take = function (array $item) use (&$picked, &$seen): void {
            $key = $item['title'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $picked[] = $item;
            }
        };

        // Best-scoring item per tag, so every tag has a contender in the shortlist.
        foreach ($tagText as $text) {
            $kw = $this->keywords($text);
            if (empty($kw)) {
                continue;
            }

            $bestItem = null;
            $bestScore = 0;
            foreach ($items as $item) {
                $score = count(array_intersect($kw, $this->keywords($item['title'].' '.$item['summary'])));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestItem = $item;
                }
            }

            if ($bestItem !== null) {
                $take($bestItem);
            }
        }

        // Top up with random items for breadth (topics no tag keyword-matched).
        foreach ($items as $item) {
            if (count($picked) >= $limit) {
                break;
            }
            $take($item);
        }

        return array_slice($picked, 0, $limit);
    }

    /**
     * Reduce text to a set of lower-cased, meaningful keyword tokens for crude
     * relevance matching. Drops short tokens, generic forum/filler words, and
     * pure-numeric tokens (e.g. a year like "2026" in a tag description otherwise
     * matches every story that happens to mention the year).
     *
     * @return string[]
     */
    protected function keywords(string $text): array
    {
        // Generic forum-furniture and filler words that create false matches. We keep
        // genuinely topical words (e.g. "news", "current") — they're what a news-style
        // tag is actually about, and the per-item bucketing prevents any single tag
        // from dominating.
        $stop = ['the', 'and', 'for', 'with', 'about', 'this', 'that', 'are', 'section',
            'discussions', 'discussion', 'forum', 'general', 'corner', 'talk', 'chat',
            'topic', 'topics', 'more', 'here', 'find', 'channel', 'anything', 'everything',
            'kick', 'back', 'grab', 'discuss', 'chit',
            'your', 'you', 'from', 'into', 'have', 'has', 'will', 'what', 'when', 'where'];

        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];

        $words = array_filter($words, function (string $w) use ($stop) {
            return mb_strlen($w) >= 4
                && !in_array($w, $stop, true)
                && !ctype_digit($w); // drop pure numbers like years
        });

        return array_values(array_unique($words));
    }

    /**
     * A handful of current headlines (titles only) for use as ambient "current
     * knowledge" a bot can draw on in conversation. Randomised so different bots
     * surface different stories. Empty if no feeds are available.
     *
     * @return string[]
     */
    public function recentHeadlines(int $limit = 8): array
    {
        $titles = array_filter(array_map(fn (array $i) => $i['title'], $this->allItems()));

        if (empty($titles)) {
            return [];
        }

        shuffle($titles);

        return array_slice(array_values(array_unique($titles)), 0, $limit);
    }

    /**
     * Parsed items for one feed URL, cached. Returns [] on any failure.
     *
     * @return array<int, array{title: string, summary: string, link: string}>
     */
    protected function itemsFor(string $url): array
    {
        return $this->cache->remember(
            'ianm-ai-chatterbox.feed.'.md5($url),
            self::CACHE_TTL,
            fn () => $this->fetchAndParse($url)
        );
    }

    /**
     * @return array<int, array{title: string, summary: string, link: string}>
     */
    protected function fetchAndParse(string $url): array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::FETCH_TIMEOUT,
                    'user_agent' => 'Mozilla/5.0 (compatible; FlarumAIUsers/1.0)',
                    'follow_location' => 1,
                ],
            ]);

            $body = @file_get_contents($url, false, $context);

            if ($body === false || $body === '') {
                return [];
            }

            $xml = @simplexml_load_string($body);

            if ($xml === false) {
                return [];
            }

            return $this->parse($xml);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Extract items from either RSS (<channel><item>) or Atom (<entry>).
     *
     * @return array<int, array{title: string, summary: string, link: string}>
     */
    protected function parse(\SimpleXMLElement $xml): array
    {
        $items = [];

        // RSS 2.0
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = $this->normalise(
                    (string) $item->title,
                    (string) $item->description,
                    (string) $item->link
                );
            }
        }

        // Atom
        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $items[] = $this->normalise(
                    (string) $entry->title,
                    (string) ($entry->summary ?: $entry->content),
                    $this->atomLink($entry)
                );
            }
        }

        // Drop items without a usable title.
        return array_values(array_filter($items, fn (array $i) => $i['title'] !== ''));
    }

    /**
     * Atom links live in the href attribute; prefer rel="alternate" (the canonical
     * article URL) and fall back to the first link.
     */
    protected function atomLink(\SimpleXMLElement $entry): string
    {
        if (!isset($entry->link)) {
            return '';
        }

        foreach ($entry->link as $link) {
            if ((string) ($link['rel'] ?? '') === 'alternate') {
                return (string) $link['href'];
            }
        }

        return (string) $entry->link[0]['href'];
    }

    /**
     * @return array{title: string, summary: string, link: string}
     */
    protected function normalise(string $title, string $summary, string $link = ''): array
    {
        $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5));
        $summary = trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5));
        $link = trim($link);

        if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
            $link = '';
        }

        if (mb_strlen($summary) > self::SUMMARY_LIMIT) {
            $summary = mb_substr($summary, 0, self::SUMMARY_LIMIT).'…';
        }

        return ['title' => $title, 'summary' => $summary, 'link' => $link];
    }
}

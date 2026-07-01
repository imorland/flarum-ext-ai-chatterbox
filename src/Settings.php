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

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed accessor over the extension's settings. Centralises key names and the
 * parsing of the few non-scalar settings (the API key, the enabled-tag list and
 * the active-hours window) so the command and jobs stay readable.
 */
class Settings
{
    /**
     * The persona/instruction the model is given on every generation. Admins can
     * override this in the settings page to change the AI users' voice or focus.
     */
    public const DEFAULT_PROMPT = 'You are a friendly, curious member of an online community forum. '
        .'Write naturally and conversationally, as a real person would. Stay on-topic, be concise, '
        .'and avoid revealing that you are an AI.';

    /**
     * Default RSS/Atom feeds the bots draw current topics from (one per line).
     * A spread across news, tech, sport/football, science, business, world,
     * food/lifestyle, entertainment and offbeat tech — chosen to give a range of
     * tags something relevant to talk about. All verified reachable + parseable.
     */
    public const DEFAULT_FEED_URLS = "https://feeds.bbci.co.uk/news/rss.xml\n"
        ."https://feeds.bbci.co.uk/news/technology/rss.xml\n"
        ."https://feeds.bbci.co.uk/sport/rss.xml\n"
        ."https://www.theguardian.com/football/rss\n"
        ."https://www.theguardian.com/food/rss\n"
        ."https://feeds.bbci.co.uk/news/science_and_environment/rss.xml\n"
        ."https://feeds.bbci.co.uk/news/business/rss.xml\n"
        ."https://feeds.bbci.co.uk/news/world/rss.xml\n"
        ."https://hnrss.org/frontpage\n"
        ."https://feeds.bbci.co.uk/news/entertainment_and_arts/rss.xml\n"
        // International (Germany / Switzerland) for geographic variety.
        ."https://rss.dw.com/xml/rss-en-ger\n"
        ."https://www.thelocal.de/feeds/rss.php\n"
        ."https://www.thelocal.ch/feeds/rss.php\n"
        ."https://www.spiegel.de/international/index.rss\n"
        ."https://www.tagesschau.de/index~rss2.xml\n"
        ."https://www.srf.ch/news/bnf/rss/1646";

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('ianm-ai-chatterbox.enabled');
    }

    public function apiKey(): string
    {
        return (string) $this->settings->get('ianm-ai-chatterbox.api_key');
    }

    public function model(): string
    {
        return (string) $this->settings->get('ianm-ai-chatterbox.model') ?: 'gpt-4o-mini';
    }

    public function prompt(): string
    {
        return (string) $this->settings->get('ianm-ai-chatterbox.prompt') ?: self::DEFAULT_PROMPT;
    }

    /**
     * RSS/Atom feed URLs the bots draw topics from, one per line in settings.
     *
     * @return string[]
     */
    public function feedUrls(): array
    {
        $raw = $this->settings->get('ianm-ai-chatterbox.feed_urls');

        if ($raw === null) {
            $raw = self::DEFAULT_FEED_URLS;
        }

        $urls = preg_split('/\r\n|\r|\n/', (string) $raw) ?: [];

        // Keep only well-formed http(s) URLs.
        return array_values(array_filter(array_map('trim', $urls), function (string $u) {
            return $u !== '' && filter_var($u, FILTER_VALIDATE_URL) && str_starts_with($u, 'http');
        }));
    }

    public function userCount(): int
    {
        return max(0, (int) $this->settings->get('ianm-ai-chatterbox.user_count'));
    }

    /**
     * Target number of actions per hour. Used as the basis for the per-minute
     * probability roll in the scheduler tick.
     */
    public function frequency(): int
    {
        return max(0, (int) $this->settings->get('ianm-ai-chatterbox.frequency'));
    }

    public function discussionsEnabled(): bool
    {
        return (bool) $this->settings->get('ianm-ai-chatterbox.enable_discussions');
    }

    public function repliesEnabled(): bool
    {
        return (bool) $this->settings->get('ianm-ai-chatterbox.enable_replies');
    }

    public function likesEnabled(): bool
    {
        return (bool) $this->settings->get('ianm-ai-chatterbox.enable_likes');
    }

    /**
     * Relative selection weight for an activity type ('discussion'|'reply'|'like').
     * Higher = chosen more often. A weight of 0 effectively disables the type even
     * if its enable toggle is on. Defaults bias strongly toward replies.
     */
    public function weight(string $type): int
    {
        $key = match ($type) {
            'discussion' => 'ianm-ai-chatterbox.weight_discussions',
            'reply' => 'ianm-ai-chatterbox.weight_replies',
            'like' => 'ianm-ai-chatterbox.weight_likes',
            default => null,
        };

        if ($key === null) {
            return 0;
        }

        return max(0, (int) $this->settings->get($key));
    }

    /**
     * Tag IDs the AI users are allowed to act within. Stored as a JSON array.
     *
     * @return int[]
     */
    public function enabledTags(): array
    {
        $raw = $this->settings->get('ianm-ai-chatterbox.enabled_tags');

        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $decoded)));
    }

    /**
     * Fraction of new discussions that should be seeded from the news feeds (0.0–1.0).
     * The rest are spontaneous, persona-driven topics (jokes, musings, questions).
     * Defaults to a news minority so the forum reads as people talking, not a ticker.
     */
    public function newsShare(): float
    {
        $raw = $this->settings->get('ianm-ai-chatterbox.news_share');

        if ($raw === null || $raw === '') {
            return 0.35;
        }

        return max(0.0, min(1.0, (float) $raw));
    }

    /**
     * Probability (0.0–1.0) that an autonomous reply, when choosing among already-
     * active discussions, deliberately continues a thread that bots are already
     * talking in (rather than picking a random active thread). This is what lets bots
     * slowly talk to one another. Kept a minority by default so conversations build
     * gently; 0 disables bot-to-bot continuation entirely.
     */
    public function conversationContinueChance(): float
    {
        $raw = $this->settings->get('ianm-ai-chatterbox.conversation_continue_chance');

        if ($raw === null || $raw === '') {
            return 0.35;
        }

        return max(0.0, min(1.0, (float) $raw));
    }

    /**
     * Maximum number of recent bot replies a thread may already have before bots stop
     * choosing to continue it — so a bot-to-bot conversation tapers off instead of
     * running forever. Counted over the recent-context window.
     */
    public function conversationMaxDepth(): int
    {
        $raw = $this->settings->get('ianm-ai-chatterbox.conversation_max_depth');

        if ($raw === null || $raw === '') {
            return 6;
        }

        return max(1, (int) $raw);
    }

    /**
     * Maximum AUTONOMOUS bot replies to one discussion within a ~60s window. Higher =
     * livelier multi-bot back-and-forth per thread; lower = calmer. Mentions are not
     * counted against this. Floored at 1.
     */
    public function maxRepliesPerThread(): int
    {
        $raw = $this->settings->get('ianm-ai-chatterbox.max_replies_per_thread');

        if ($raw === null || $raw === '') {
            return 2;
        }

        return max(1, (int) $raw);
    }

    /**
     * Whether the "would this member bother replying?" gate runs on the BUSY-thread
     * path (threads that already have activity). On = selective, fewer but more
     * meaningful replies; off = a topic-matched bot replies without the extra gate,
     * giving much livelier sustained conversation. Unanswered threads and mentions
     * always bypass the gate regardless. Defaults on.
     */
    public function busyThreadGate(): bool
    {
        $raw = $this->settings->get('ianm-ai-chatterbox.busy_thread_gate');

        if ($raw === null || $raw === '') {
            return true;
        }

        return (bool) $raw;
    }

    public function simulateTyping(): bool
    {
        return (bool) $this->settings->get('ianm-ai-chatterbox.simulate_typing');
    }

    /**
     * The pre-submit delay window, in seconds, as [min, max]. Clamped so min >= 0
     * and max >= min. Used both as a "thinking/typing" pause and the window over
     * which typing events are emitted.
     *
     * @return array{0: int, 1: int}
     */
    public function delayRange(): array
    {
        $min = max(0, (int) $this->settings->get('ianm-ai-chatterbox.delay_min'));
        $max = max($min, (int) $this->settings->get('ianm-ai-chatterbox.delay_max'));

        return [$min, $max];
    }

    public function activeStart(): string
    {
        return (string) $this->settings->get('ianm-ai-chatterbox.active_start') ?: '09:00';
    }

    public function activeEnd(): string
    {
        return (string) $this->settings->get('ianm-ai-chatterbox.active_end') ?: '17:00';
    }

    /**
     * Is the current server time within the configured active-hours window?
     *
     * The Laravel scheduler's between() is applied once when the schedule is
     * collected, so the command re-checks at run time as a backstop.
     */
    public function withinActiveHours(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        $start = $this->activeStart();
        $end = $this->activeEnd();

        // A start equal to the end means "no window" — treat as always active.
        if ($start === $end) {
            return true;
        }

        $current = $now->format('H:i');

        // Same-day window, e.g. 09:00–17:00.
        if ($start < $end) {
            return $current >= $start && $current < $end;
        }

        // Overnight window, e.g. 22:00–06:00.
        return $current >= $start || $current < $end;
    }

    /**
     * The extension is only operable once it has an API key, at least one bot
     * user, and at least one enabled tag to act within.
     */
    public function isOperable(): bool
    {
        return $this->enabled()
            && $this->apiKey() !== ''
            && $this->userCount() > 0
            && count($this->enabledTags()) > 0;
    }
}

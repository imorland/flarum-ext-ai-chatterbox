<?php

/*
 * This file is part of ianm/ai-chatterbox.
 *
 * Copyright (c) 2026 IanM.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace IanM\AIChatterbox\Jobs;

use Flarum\Discussion\Discussion;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use IanM\AIChatterbox\BotUserManager;
use IanM\AIChatterbox\ContentWriter;
use IanM\AIChatterbox\FeedReader;
use IanM\AIChatterbox\OpenAIClient;
use IanM\AIChatterbox\Settings;
use IanM\AIChatterbox\TypingSimulator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Generates and posts a brand-new discussion, authored by a random bot user, in
 * a valid combination of the configured tags. The tag set is assembled to satisfy
 * the forum's primary/secondary tag count requirements; if the enabled tags can't
 * satisfy them, the job skips rather than failing.
 */
class GenerateDiscussionJob extends AbstractJob
{
    /**
     * How many recent discussion titles to consider when avoiding repeats — used
     * both for the model's avoid-list and the lexical similarity check.
     */
    protected const AVOID_RECENT_TITLES = 30;

    /**
     * How many recent bot discussions to consider when balancing tag coverage. A
     * rolling window so newly-added tags catch up and coverage rebalances over time
     * rather than being skewed by all-time history.
     */
    protected const COVERAGE_WINDOW = 40;

    /**
     * How long to wait for the discussion-creation lock before giving up. Long
     * enough for one in-flight discussion (typing delay + generation) to finish.
     */
    protected const LOCK_WAIT_SECONDS = 20;

    /**
     * Fraction of significant words a candidate title may share with a recent one
     * before it's treated as a near-duplicate (and the topic is regenerated/skipped).
     */
    protected const TITLE_SIMILARITY_THRESHOLD = 0.6;

    public function handle(
        Settings $settings,
        BotUserManager $bots,
        OpenAIClient $client,
        ContentWriter $writer,
        SettingsRepositoryInterface $rawSettings,
        TypingSimulator $typing,
        FeedReader $feeds,
        Cache $cache
    ): void {
        if (! $settings->isOperable()) {
            return;
        }

        $actor = $bots->randomBot();

        if (! $actor) {
            return;
        }

        $tags = Tag::query()->whereIn('id', $settings->enabledTags())->get();

        [$primary] = $tags->partition(fn (Tag $t) => $this->isPrimary($t));

        if ($primary->isEmpty()) {
            // No usable primary tag among the enabled set — nothing to anchor on.
            return;
        }

        $contextTag = null;
        $newsItem = null;

        // Decide up front whether THIS thread is news-driven or spontaneous. Real
        // forums are mostly people's own thoughts, jokes and questions, with the odd
        // reaction to current events — not a news ticker. We only attempt news routing
        // on a news roll; otherwise we go straight to a persona-driven spontaneous
        // topic. The share is admin-tunable (news_share).
        $wantNews = $this->rollNews($settings->newsShare());

        // Recent titles steer both routing (toward under-represented sections) and
        // the later duplicate check.
        $recentForRouting = $this->recentTitles();

        if ($wantNews) {
            // Route topic-first and let the MODEL decide the fit: keyword scoring only
            // shortlists a diverse set of candidate news items (cheaply), then one
            // classification call picks which enabled tag — by its name + description —
            // a news item genuinely belongs in, or none. Fully dynamic: the only signal
            // is each tag's own metadata, so it works with any forum's tags.
            $candidates = $feeds->shortlistItems($this->tagText($primary));

            if (! empty($candidates)) {
                $routed = $client->classifyItemToTag($candidates, $this->tagDescriptors($primary), $recentForRouting);

                if ($routed !== null) {
                    $contextTag = $primary->firstWhere('id', $routed['tagId']);
                    $newsItem = $candidates[$routed['itemIndex']] ?? null;
                }
            }
        }

        // Spontaneous (the default, and the fallback when no news fit): pick an enabled
        // primary tag, biased toward ones that have had FEWER recent bot discussions,
        // and let the bot post a topic of its own. The bias keeps coverage even and,
        // crucially, lets a freshly-added tag (zero recent threads) get picked up
        // promptly instead of waiting on uniform-random luck. Fully dynamic — driven
        // only by live per-tag counts, no hardcoded names.
        if ($contextTag === null) {
            $contextTag = $this->pickUnderusedTag($primary, $bots);
            $newsItem = null;
        }

        // Assemble the full tag set (the routed primary + any required secondaries)
        // satisfying the forum's count rules; skip if they can't be met.
        $chosen = $this->chooseTags($contextTag, $tags, $rawSettings);

        if ($chosen === null) {
            return;
        }

        // Serialize discussion creation so two jobs in the same tick can't both seed
        // from the same fresh headline and post near-duplicate threads. Holding the
        // lock means the second job reads the first's just-created title (below) and
        // is steered onto a different topic. Block briefly so it still posts.
        $lock = $cache->lock('ianm-ai-chatterbox.discussion', 90);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException $e) {
            // Couldn't acquire in time — skip this one rather than risk a duplicate.
            return;
        }

        try {
            // The authoring bot's persona, so the thread is written in its own voice.
            $persona = $bots->personaFor($actor);

            // Recent titles steer the model away from repeats (read inside the lock,
            // so it includes a sibling job's just-created thread). If the routed news
            // item duplicates a recent thread's subject, drop it and let the model
            // invent something fresh rather than re-cover the same story.
            $avoidTitles = $this->recentTitles();

            if ($newsItem !== null && $this->duplicatesRecent($newsItem['title'], $avoidTitles)) {
                $newsItem = null;
            }

            $generated = $client->generateDiscussion(
                $contextTag->name,
                (string) $contextTag->description,
                $newsItem,
                $avoidTitles,
                $persona
            );

            // If the generated title is too close to a recent one, regenerate once
            // more without a news seed before giving up — repeats are the main
            // complaint, so we'd rather skip than post a near-duplicate.
            if ($this->duplicatesRecent($generated['title'], $avoidTitles)) {
                $generated = $client->generateDiscussion(
                    $contextTag->name,
                    (string) $contextTag->description,
                    null,
                    $avoidTitles,
                    $persona
                );

                if ($this->duplicatesRecent($generated['title'], $avoidTitles)) {
                    // Still a near-duplicate — skip rather than add yet another repeat.
                    return;
                }
            }

            $tagIds = $chosen->map(fn (Tag $t) => $t->id)->all();

            // Show the bot "composing" in the chosen tags (the discussion-list dot)
            // for the configured delay, then create the discussion.
            $typing->newDiscussionWithTyping(
                $tagIds,
                $actor,
                fn () => $writer->createDiscussion($actor, $generated['title'], $generated['body'], $tagIds)
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Roll whether this discussion should be news-driven, given the configured share
     * (0.0–1.0). A share of 0 means never use news; 1 means always try. The randomness
     * keeps the news/spontaneous mix organic rather than a fixed alternation.
     */
    protected function rollNews(float $share): bool
    {
        if ($share <= 0.0) {
            return false;
        }

        if ($share >= 1.0) {
            return true;
        }

        return (mt_rand(0, 999) / 1000) < $share;
    }

    /**
     * Pick an enabled primary tag for a spontaneous discussion, biased toward tags
     * with fewer recent bot-started discussions so coverage stays even and newly
     * added tags (with none yet) get used promptly. Weight = 1/(1+recentCount), so a
     * tag with 0 recent threads is far likelier than one with many, but every tag
     * keeps a non-zero chance. Fully dynamic: driven only by live counts.
     *
     * @param Collection<int, Tag> $primary
     */
    protected function pickUnderusedTag(Collection $primary, BotUserManager $bots): Tag
    {
        if ($primary->count() === 1) {
            return $primary->first();
        }

        $counts = $this->recentBotDiscussionCountsByTag($bots, $primary);

        $tags = $primary->values();
        $scarcityCounts = $tags->map(fn (Tag $t) => $counts[(int) $t->id] ?? 0)->all();

        $index = $this->weightByScarcity($scarcityCounts);

        return $tags[$index] ?? $tags->last();
    }

    /**
     * Given a per-item "recent usage" count, weighted-pick an index biased toward the
     * least-used items: weight = 1/(1+count), so 0-count items are far likelier but
     * every item keeps a non-zero chance. Pure (no DB), so it's unit-testable.
     *
     * @param array<int, int> $counts
     */
    protected function weightByScarcity(array $counts): int
    {
        if (empty($counts)) {
            return 0;
        }

        $weights = [];
        $total = 0.0;
        foreach ($counts as $count) {
            $weight = 1.0 / (1 + max(0, $count));
            $weights[] = $weight;
            $total += $weight;
        }

        $roll = (mt_rand(0, 999) / 1000) * $total;

        foreach ($weights as $i => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $i;
            }
        }

        return count($counts) - 1; // floating-point fallback
    }

    /**
     * Count recent bot-started discussions per (enabled primary) tag, so the
     * spontaneous picker can favour under-represented tags. Looks at a recent window
     * only, so coverage rebalances over time rather than being dominated by all-time
     * history.
     *
     * @param Collection<int, Tag> $primary
     * @return array<int, int> tag id => recent bot-discussion count
     */
    protected function recentBotDiscussionCountsByTag(BotUserManager $bots, Collection $primary): array
    {
        $botIds = $bots->query()->pluck('id')->all();

        if (empty($botIds)) {
            return [];
        }

        $tagIds = $primary->map(fn (Tag $t) => (int) $t->id)->all();

        $recentDiscussionIds = Discussion::query()
            ->whereIn('user_id', $botIds)
            ->latest('created_at')
            ->limit(self::COVERAGE_WINDOW)
            ->pluck('id')
            ->all();

        if (empty($recentDiscussionIds)) {
            return [];
        }

        $rows = Discussion::query()->getConnection()->table('discussion_tag')
            ->whereIn('discussion_id', $recentDiscussionIds)
            ->whereIn('tag_id', $tagIds)
            ->get(['tag_id']);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->tag_id] = ($counts[(int) $row->tag_id] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The most recent discussion titles, for steering the model away from repeats.
     *
     * @return string[]
     */
    protected function recentTitles(): array
    {
        return Discussion::query()
            ->whereNull('hidden_at')
            ->latest('created_at')
            ->limit(self::AVOID_RECENT_TITLES)
            ->pluck('title')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Build a tag set satisfying the configured minimum primary/secondary counts
     * (respecting maximums), anchored on the already-routed primary tag and drawn
     * only from the enabled tags. Returns null if the requirements can't be met.
     */
    protected function chooseTags(Tag $routedPrimary, Collection $tags, SettingsRepositoryInterface $settings): ?Collection
    {
        [$primary, $secondary] = $tags->partition(fn (Tag $t) => $this->isPrimary($t));

        $minPrimary = (int) $settings->get('flarum-tags.min_primary_tags', 0);
        $maxPrimary = (int) $settings->get('flarum-tags.max_primary_tags', 0);
        $minSecondary = (int) $settings->get('flarum-tags.min_secondary_tags', 0);
        $maxSecondary = (int) $settings->get('flarum-tags.max_secondary_tags', 0);

        // Take at least the minimum of each (and at least one primary, since we always
        // have a routed one), capped at the maximum where set.
        $primaryWanted = max($minPrimary, 1);
        if ($maxPrimary > 0) {
            $primaryWanted = min($primaryWanted, $maxPrimary);
        }

        $secondaryWanted = $minSecondary;
        if ($maxSecondary > 0) {
            $secondaryWanted = min($secondaryWanted, $maxSecondary);
        }

        if ($primary->count() < $primaryWanted || $secondary->count() < $secondaryWanted) {
            return null;
        }

        // Keep the routed primary first, then top up with other primaries if the
        // forum requires more than one.
        $chosenPrimary = $primary->reject(fn (Tag $t) => $t->id === $routedPrimary->id)
            ->shuffle()
            ->take(max(0, $primaryWanted - 1))
            ->prepend($routedPrimary);

        return $chosenPrimary
            ->concat($secondary->shuffle()->take($secondaryWanted))
            ->values();
    }

    /**
     * Map a collection of tags to [id => "name description"] for the keyword-based
     * shortlist scoring.
     *
     * @param Collection<int, Tag> $tags
     * @return array<int, string>
     */
    protected function tagText(Collection $tags): array
    {
        $out = [];

        foreach ($tags as $tag) {
            $out[(int) $tag->id] = $tag->name.' '.(string) $tag->description;
        }

        return $out;
    }

    /**
     * Map a collection of tags to the {id, name, description} descriptors the model
     * routes against.
     *
     * @param Collection<int, Tag> $tags
     * @return array<int, array{id: int, name: string, description: string}>
     */
    protected function tagDescriptors(Collection $tags): array
    {
        return $tags->map(fn (Tag $t) => [
            'id' => (int) $t->id,
            'name' => (string) $t->name,
            'description' => (string) $t->description,
        ])->values()->all();
    }

    /**
     * Is a candidate title a near-duplicate of any recent one? Compares on the set
     * of significant words (order-independent) so reworded retreads of the same
     * subject ("Germany's World Cup exit" vs "What went wrong for Germany") are
     * caught, not just verbatim repeats. Purely lexical — no tag-specific rules.
     *
     * @param string[] $recentTitles
     */
    protected function duplicatesRecent(string $candidate, array $recentTitles): bool
    {
        $candWords = $this->significantWords($candidate);

        if (count($candWords) === 0) {
            return false;
        }

        foreach ($recentTitles as $existing) {
            $existingWords = $this->significantWords($existing);

            if (count($existingWords) === 0) {
                continue;
            }

            $overlap = count(array_intersect($candWords, $existingWords));

            // Jaccard-style similarity against the smaller title, so a short title
            // fully contained in a longer one still counts as a duplicate.
            $similarity = $overlap / min(count($candWords), count($existingWords));

            if ($similarity >= self::TITLE_SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * Significant lower-cased words in a title (drops short and common words) for
     * order-independent similarity comparison.
     *
     * @return string[]
     */
    protected function significantWords(string $text): array
    {
        $stop = ['the', 'and', 'for', 'with', 'about', 'this', 'that', 'are', 'was',
            'has', 'have', 'will', 'what', 'why', 'how', 'who', 'when', 'your', 'you',
            'from', 'into', 'out', 'over', 'its', 'their', 'they', 'our'];

        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];

        $words = array_filter($words, fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, $stop, true));

        return array_values(array_unique($words));
    }

    protected function isPrimary(Tag $tag): bool
    {
        // Matches flarum/tags: a primary tag has a position and no parent.
        return $tag->position !== null && $tag->parent_id === null;
    }
}

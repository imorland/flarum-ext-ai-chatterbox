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

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use IanM\AIChatterbox\BotUserManager;
use IanM\AIChatterbox\ContentWriter;
use IanM\AIChatterbox\FeedReader;
use IanM\AIChatterbox\Mentions;
use IanM\AIChatterbox\OpenAIClient;
use IanM\AIChatterbox\Settings;
use IanM\AIChatterbox\TypingSimulator;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Generates and posts a reply, authored by a random bot user, on a recent
 * discussion within one of the configured tags.
 */
class GenerateReplyJob extends AbstractJob
{
    /**
     * How many recent discussions to choose a reply target from.
     */
    protected const CANDIDATE_POOL = 20;


    /**
     * Length (seconds) of the rolling window the autonomous per-discussion cap is
     * measured over. Matches the ~1-minute scheduler tick cadence.
     */
    protected const WINDOW_SECONDS = 60;

    /**
     * Only treat a thread as a worth-seeding "unanswered" target if it was created
     * within this many hours. Older unanswered threads are effectively dead (and on a
     * seeded forum are mostly placeholder/test data), so seeding them just wastes
     * reply jobs that then bail without posting.
     */
    protected const UNANSWERED_MAX_AGE_HOURS = 48;

    /**
     * How many recent posts to feed the model as conversation context. Enough to
     * follow the thread and respond coherently (including to a mention of the bot),
     * without sending the entire discussion every time.
     */
    protected const CONTEXT_POSTS = 12;

    /**
     * Probability of prepending a post-mention of the post being replied to, so
     * some replies read as direct, threaded responses.
     */
    protected const POST_MENTION_CHANCE = 0.4;

    /**
     * Optional explicit response target, set when the job is dispatched to answer a
     * specific notification (a bot replying to a mention). When all are null the job
     * autonomously picks a discussion to reply to.
     */
    public function __construct(
        protected ?int $targetBotId = null,
        protected ?int $targetDiscussionId = null,
        protected ?int $mentionPostId = null
    ) {
        parent::__construct();
    }

    public function handle(
        Settings $settings,
        BotUserManager $bots,
        OpenAIClient $client,
        ContentWriter $writer,
        TypingSimulator $typing,
        Cache $cache,
        FeedReader $feeds
    ): void {
        if (!$settings->isOperable()) {
            return;
        }

        $directed = $this->targetBotId !== null;
        $mentionPost = null;
        $isUnanswered = false;

        if ($directed) {
            // Directed response to a specific notification/mention (from the
            // NotificationResponder, which owns mention handling + read-marking).
            [$actor, $discussion, $mentionPost] = $this->resolveExplicitTarget($bots);
        } else {
            // Autonomous. Mention answering is NOT done here — that belongs to the
            // NotificationResponder so a mention is answered exactly once.
            //
            // Prioritise UNANSWERED threads, and for those pick the bot whose persona
            // best fits the topic (a football thread → a sport-leaning bot) so the
            // reply is on-voice AND the gate downstream actually passes — otherwise a
            // randomly-chosen, uninterested bot just declines and the thread stays
            // unanswered. Falls back to the random-bot / busier-thread path when there
            // are no unanswered threads.
            [$actor, $discussion] = $this->pickUnansweredWithActor($settings->enabledTags(), $bots, $client);

            // An unanswered thread we've already matched to a best-fit persona skips the
            // downstream reply gate: the matching IS the "would this member engage?"
            // decision, and we want every unanswered thread to actually get its first
            // reply rather than be second-guessed and left hanging.
            $isUnanswered = $discussion !== null;

            if ($discussion === null) {
                // Busy-thread path. Pick the thread (any bot scopes visibility), then —
                // as with unanswered threads — choose the bot whose persona best fits the
                // topic. A random actor is usually uninterested in a given thread, so the
                // reply gate declines it; a topic-matched actor makes the gate pass and the
                // reply on-voice, which is the difference between sparse and lively. The
                // gate STILL runs here, so bots don't pile onto active threads needlessly.
                $discussion = $this->pickBusyDiscussion($settings, $bots, $bots->randomBot());
                $actor = $discussion ? $this->chooseActorForThread($discussion, $bots, $client) ?? $bots->randomBot() : null;
            }
        }

        if (!$actor) {
            return;
        }

        if (!$discussion) {
            return;
        }

        // A per-discussion lock serialises concurrent reply jobs so two bots can't
        // post at the same instant or race the checks below. It is held only for the
        // brief critical section (count check + DB re-read); generation/posting happens
        // after release so the lock never spans a slow OpenAI call.
        $lock = $cache->lock('ianm-ai-chatterbox.reply.'.$discussion->id, 60);

        if (!$lock->get()) {
            return;
        }

        try {
            // Re-read the latest poster from the DB now we hold the lock — the model
            // loaded at selection time may be stale. Skip if this bot is last (no bot
            // replies twice in a row).
            $lastPosterId = Discussion::query()->where('id', $discussion->id)->value('last_posted_user_id');

            if ((int) $lastPosterId === (int) $actor->id) {
                return;
            }

            // Autonomous replies are capped per discussion per rolling window so bots
            // don't swarm a single thread — the admin-tunable limit sets how lively a
            // single thread can get. Mention-driven (directed) replies bypass the cap:
            // a pinged bot always answers.
            if (!$directed && !$this->claimAutonomousSlot($cache, (int) $discussion->id, $settings->maxRepliesPerThread())) {
                return;
            }

            $persona = $bots->personaFor($actor);
        } finally {
            $lock->release();
        }

        // Generation + posting happen outside the lock (they can be slow). The slot is
        // already claimed and the typing/per-discussion safety still applies via the
        // last-poster checks at post time.
        $this->generateAndPost($discussion, $actor, $mentionPost, $settings, $client, $writer, $typing, $feeds, $persona, $isUnanswered);
    }

    /**
     * Claim one of the limited autonomous-reply slots for a discussion in the current
     * rolling window. Returns true if a slot was available (and consumes it), false if
     * the discussion has already had its allowance of autonomous bot replies this
     * window. The counter key expires after WINDOW_SECONDS so the allowance refreshes.
     */
    protected function claimAutonomousSlot(Cache $cache, int $discussionId, int $max): bool
    {
        $key = 'ianm-ai-chatterbox.reply-count.'.$discussionId;

        // First claimer initialises the counter with the window TTL; subsequent
        // claimers within the window just increment. (Called under the per-discussion
        // lock, so this read-modify-write is not racy.)
        $count = (int) $cache->get($key, 0);

        if ($count >= $max) {
            return false;
        }

        $cache->put($key, $count + 1, self::WINDOW_SECONDS);

        return true;
    }

    /**
     * Build the reply (with mention context if applicable) and submit it with the
     * typing simulation. Assumes the per-discussion lock is held.
     *
     * @param array<string, string>|null $persona The authoring bot's persona (voice).
     * @param bool $isUnanswered True when this is the oldest-waiting unanswered thread
     *                           already matched to a best-fit persona — skip the reply
     *                           gate so the thread reliably gets its first reply.
     */
    protected function generateAndPost(
        Discussion $discussion,
        User $actor,
        ?Post $mentionPost,
        Settings $settings,
        OpenAIClient $client,
        ContentWriter $writer,
        TypingSimulator $typing,
        FeedReader $feeds,
        ?array $persona = null,
        bool $isUnanswered = false
    ): void {
        // Recent posts, oldest first — used for the transcript, the participant
        // list (whom the reply may mention) and the post being replied to.
        $posts = $this->recentPosts($discussion);

        // Participants the reply may mention: thread authors other than the bot.
        $participants = $posts
            ->map(fn (Post $p) => $p->user)
            ->filter()
            ->filter(fn (User $u) => $u->id !== $actor->id)
            ->unique('id')
            ->values()
            ->all();

        $context = $this->buildTranscript($posts);
        $participantNames = array_map(fn (User $u) => $u->display_name, $participants);

        // If this reply was triggered by a mention of the bot, tell the model who
        // addressed it and what they said, so it answers directly.
        $addressedBy = null;
        if ($mentionPost && $mentionPost->user) {
            $addressedBy = $mentionPost->user->display_name;
        }

        // Reply gate: ask whether this member would actually bother replying here.
        // Real people skip most threads, so this biases toward restraint on the
        // BUSY-thread path. Bypassed for: directed (@mention) replies — always
        // answered; unanswered threads already matched to a best-fit persona — every
        // unanswered thread should get its first reply; and entirely when the
        // BUSY_THREAD_GATE switch is off (unleashed mode for livelier conversation).
        $gateApplies = $mentionPost === null && !$isUnanswered && $settings->busyThreadGate();

        if ($gateApplies && !$client->shouldReply($discussion->title, $context, $persona)) {
            return;
        }

        // Give the bot current-events awareness as background knowledge. The model
        // only draws on it if a headline is directly relevant to this thread — it is
        // instructed not to change the subject (forum replies stay on topic).
        $headlines = $feeds->recentHeadlines();

        // Suppress the [SKIP] opt-out for autonomous replies that have already cleared
        // the gate decision: unanswered threads (always committed) and, when the busy
        // gate is off, busy threads too — otherwise generation could still self-skip
        // and we'd lose the liveliness the unleashed mode is meant to produce.
        $mustReply = $mentionPost === null && ($isUnanswered || !$settings->busyThreadGate());

        $body = $client->generateReply($discussion->title, $context, $participantNames, $addressedBy, $headlines, $persona, $mustReply);

        if ($body === '') {
            return;
        }

        // Convert any plain @Name the model used into linked user mentions.
        $body = Mentions::link($body, $participants);

        // When answering a mention, post-mention the asker so it threads as a direct
        // reply. Otherwise sometimes open with a post-mention of the latest post.
        if ($mentionPost && $mentionPost->user && $mentionPost->user->id !== $actor->id) {
            $body = Mentions::post($mentionPost)."\n\n".$body;
        } else {
            $replyTo = $posts->last();
            if ($replyTo && $replyTo->user && $replyTo->user->id !== $actor->id
                && (mt_rand() / mt_getrandmax()) < self::POST_MENTION_CHANCE) {
                $body = Mentions::post($replyTo)."\n\n".$body;
            }
        }

        // Show the bot "typing" in this discussion for the configured delay, then
        // submit the reply.
        $typing->replyWithTyping(
            $discussion->id,
            $actor,
            fn () => $writer->createReply($actor, $discussion->id, $body)
        );
    }

    /**
     * Resolve the explicit (bot, discussion, mentionPost) target this job was
     * dispatched with, when responding to a specific notification. Validates the
     * bot is a real bot and the discussion is visible to it.
     *
     * @return array{0: ?User, 1: ?Discussion, 2: ?Post}
     */
    protected function resolveExplicitTarget(BotUserManager $bots): array
    {
        $bot = $bots->query()->find($this->targetBotId);

        if (!$bot) {
            return [null, null, null];
        }

        $discussion = Discussion::query()
            ->whereVisibleTo($bot)
            ->find($this->targetDiscussionId);

        if (!$discussion) {
            return [null, null, null];
        }

        $mentionPost = $this->mentionPostId !== null
            ? Post::query()->with('user')->find($this->mentionPostId)
            : null;

        return [$bot, $discussion, $mentionPost];
    }

    /**
     * Pick a recent discussion in an enabled tag that the actor can see. Scoped
     * with whereVisibleTo so the discussion is fetchable through the API as this
     * actor (and thus replyable once the bot group has the tag's permissions).
     *
     * Prioritises UNANSWERED threads (no replies yet): a discussion sitting at zero
     * replies most needs attention, and a bot answering first keeps the forum feeling
     * alive rather than letting posts die unanswered.
     *
     * Unanswered threads are queried in their OWN right (oldest-waiting first), not
     * merely re-ranked within the recent-activity pool. That matters: an unanswered
     * thread's last_posted_at never advances (no replies), so on a busy forum it
     * quickly sinks below the recent-active window — i.e. the very threads we want to
     * prioritise would otherwise be the first excluded. Only when there are no
     * unanswered threads do we fall back to a random recent-active one, so bots still
     * spread across busier discussions.
     *
     * Note: Flarum's comment_count includes the opening post, so a thread with no
     * replies has comment_count == 1.
     *
     * @param int[] $tagIds
     */
    /**
     * Pick an unanswered thread to reply to AND the bot best suited to reply to it.
     *
     * We take the most recently created unanswered threads (a fresh post with no
     * reply most needs one), pick one at random (so no single thread the bots keep
     * declining can monopolise selection), then choose the actor whose persona best
     * fits that thread — so the reply is on-voice and the downstream "should I reply?"
     * gate passes instead of an uninterested random bot declining it.
     *
     * @param int[] $tagIds
     * @return array{0: ?User, 1: ?Discussion}
     */
    protected function pickUnansweredWithActor(array $tagIds, BotUserManager $bots, OpenAIClient $client): array
    {
        $bot = $bots->randomBot();

        if ($bot === null) {
            return [null, null];
        }

        // Use any bot purely to scope visibility for the candidate query; the actual
        // actor is chosen below. (Bots share the same group/permissions/visibility.)
        //
        // Only consider RECENT unanswered threads. A thread left unanswered for days is
        // dead — and on a seeded/test forum the old backlog is dominated by placeholder
        // ("A new discussion", lorem-ipsum) threads that can't be meaningfully replied
        // to, so without this bound a large share of reply jobs land on them, generate
        // nothing, and bail — starving the genuinely-recent threads. The age window
        // keeps the picker on threads worth seeding.
        $cutoff = Carbon::now()->subHours(self::UNANSWERED_MAX_AGE_HOURS);

        $unanswered = $this->replyableQuery($tagIds, $bot)
            ->where('comment_count', '<=', 1)
            ->where('created_at', '>=', $cutoff)
            ->oldest('created_at')
            ->limit(self::CANDIDATE_POOL)
            ->get();

        if ($unanswered->isEmpty()) {
            return [null, null];
        }

        // Bias toward the longest-waiting thread so an aging unanswered post (e.g. 30+
        // minutes with no reply) is answered before a just-created one — "no thread left
        // hanging". Weighting is linear by position (oldest first, since the query is
        // ordered oldest-first), so the oldest is most likely but newer ones keep a
        // non-zero chance, leaving the selection organic rather than rigidly oldest.
        $discussion = $this->pickWeightedToFront($unanswered);

        $actor = $this->chooseActorForThread($discussion, $bots, $client) ?? $bots->randomBot();

        return [$actor, $discussion];
    }

    /**
     * Weighted pick from a list ordered front-to-back by priority (here: oldest-
     * waiting first). The item at position i gets weight (n - i), so the front item is
     * the most likely but every item keeps a non-zero chance — preferring long-waiting
     * threads without rigidly always taking the single oldest.
     *
     * @param Collection<int, Discussion> $ordered
     */
    protected function pickWeightedToFront(Collection $ordered): Discussion
    {
        $items = $ordered->values();
        $n = $items->count();

        if ($n === 1) {
            return $items[0];
        }

        // Total weight = n + (n-1) + ... + 1 = n(n+1)/2.
        $total = $n * ($n + 1) / 2;
        $roll = mt_rand(1, (int) $total);

        $cumulative = 0;
        foreach ($items as $i => $item) {
            $cumulative += ($n - $i);
            if ($roll <= $cumulative) {
                return $item;
            }
        }

        return $items[0]; // fallback (shouldn't be reached)
    }

    /**
     * Choose a bot whose persona fits a thread's topic, so the thread is answered by
     * someone who'd genuinely engage. Returns a POOL-aware pick: the model ranks the
     * best-fitting members, and we choose one that ISN'T the thread's last poster (and
     * isn't the excluded actor), so a topic sustains a rotating conversation instead of
     * gridlocking on its single best-fit bot (who just posted and can't reply again).
     *
     * Fully dynamic — the model matches personas to the thread; no hardcoded maps.
     * Returns null if it can't decide (caller falls back to a random bot).
     */
    protected function chooseActorForThread(Discussion $discussion, BotUserManager $bots, OpenAIClient $client): ?User
    {
        $candidates = $bots->query()->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Build {id, persona-blurb} descriptors for the model to match against.
        $descriptors = [];
        foreach ($candidates as $bot) {
            $persona = $bots->personaFor($bot);
            $blurb = $persona === null
                ? (string) $bot->username
                : trim(($persona['interests'] ?? '').' — '.($persona['bio'] ?? ''));
            $descriptors[(int) $bot->id] = $blurb;
        }

        $firstPost = $discussion->firstPost;
        $opening = $firstPost !== null ? (string) $firstPost->content : '';

        // Ranked pool of fitting members (best first).
        $rankedIds = $client->choosePersonasForTopic($discussion->title, $opening, $descriptors);

        if (empty($rankedIds)) {
            return null;
        }

        // Prefer a fitting member who isn't the thread's last poster, so we don't pick
        // the bot that just replied (which would bail on "no two in a row"). Fall back
        // to the top fit if every ranked candidate happens to be the last poster.
        $lastPoster = (int) $discussion->last_posted_user_id;

        foreach ($rankedIds as $id) {
            if ($id !== $lastPoster) {
                return $candidates->firstWhere('id', $id);
            }
        }

        return $candidates->firstWhere('id', $rankedIds[0]);
    }

    /**
     * Pick a busier recent discussion (has replies) for when there are no unanswered
     * threads.
     *
     * To let bots *slowly* talk to one another, with a configurable probability this
     * deliberately continues a thread bots are already conversing in, rather than
     * picking a random active one. It's gentle and self-limiting by design:
     *  - only a minority of picks (conversationContinueChance) continue a bot thread;
     *  - threads that already have too many recent bot replies (conversationMaxDepth)
     *    are excluded, so a conversation tapers off instead of running away;
     *  - the per-discussion 2-replies-per-window cap and the persona reply-gate still
     *    apply downstream, and nothing here creates a mention/notification — so this
     *    cannot reignite the bot↔bot mention cascade.
     */
    protected function pickBusyDiscussion(Settings $settings, BotUserManager $bots, User $actor): ?Discussion
    {
        $tagIds = $settings->enabledTags();

        $busy = $this->replyableQuery($tagIds, $actor)
            ->where('comment_count', '>', 1)
            ->latest('last_posted_at')
            ->limit(self::CANDIDATE_POOL)
            ->get();

        if ($busy->isEmpty()) {
            return null;
        }

        // Sometimes continue an ongoing bot conversation; otherwise spread randomly.
        if (mt_rand(1, 100) <= (int) round($settings->conversationContinueChance() * 100)) {
            $continuation = $this->pickContinuableThread($busy, $bots, $settings->conversationMaxDepth(), $actor);

            if ($continuation !== null) {
                return $continuation;
            }
        }

        return $busy->shuffle()->first();
    }

    /**
     * From a pool of active threads, pick one where a bot (not this actor) recently
     * posted and the recent bot-reply count is still under the depth ceiling — i.e. a
     * conversation worth gently continuing, but not one that's already gone on long
     * enough. Returns null when none qualify (caller falls back to random).
     *
     * @param Collection<int, Discussion> $busy
     */
    protected function pickContinuableThread(Collection $busy, BotUserManager $bots, int $maxDepth, User $actor): ?Discussion
    {
        $botIds = $bots->query()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (empty($botIds)) {
            return null;
        }

        $connection = Discussion::query()->getConnection();

        $continuable = $busy->filter(function (Discussion $d) use ($botIds, $maxDepth, $actor, $connection) {
            // The most recent poster must be a bot other than this actor (so there's a
            // bot remark to respond to, and we're not replying to ourselves).
            $lastPoster = (int) $d->last_posted_user_id;
            if (!in_array($lastPoster, $botIds, true) || $lastPoster === (int) $actor->id) {
                return false;
            }

            // Count recent bot replies in this thread; skip if it's already deep, so
            // conversations taper rather than run forever.
            $recentBotReplies = $connection->table('posts')
                ->where('discussion_id', $d->id)
                ->where('type', 'comment')
                ->whereNull('hidden_at')
                ->whereIn('user_id', $botIds)
                ->orderByDesc('created_at')
                ->limit(self::CONTEXT_POSTS)
                ->count();

            return $recentBotReplies < $maxDepth;
        })->values();

        if ($continuable->isEmpty()) {
            return null;
        }

        // Prefer the most recently active among the continuable ones, so a live
        // exchange keeps gentle momentum.
        return $continuable->sortByDesc('last_posted_at')->first();
    }

    /**
     * Base query for discussions this bot may autonomously reply to: visible to the
     * actor, in an enabled tag, with a comment, and not one the bot posted in last
     * (so it never replies to itself twice running).
     *
     * @param int[] $tagIds
     * @return \Illuminate\Database\Eloquent\Builder<Discussion>
     */
    protected function replyableQuery(array $tagIds, User $actor)
    {
        return Discussion::query()
            ->whereVisibleTo($actor)
            ->whereExists(function ($query) use ($tagIds) {
                $query->selectRaw('1')
                    ->from('discussion_tag')
                    ->whereColumn('discussion_tag.discussion_id', 'discussions.id')
                    ->whereIn('discussion_tag.tag_id', $tagIds);
            })
            ->where('comment_count', '>', 0)
            // Don't let a bot reply twice in a row: skip discussions it last posted in.
            ->where(function ($query) use ($actor) {
                $query->whereNull('last_posted_user_id')
                    ->orWhere('last_posted_user_id', '!=', $actor->id);
            });
    }

    /**
     * The most recent visible comment posts, oldest first.
     *
     * @return Collection<int, Post>
     */
    protected function recentPosts(Discussion $discussion): Collection
    {
        return $discussion->comments()
            ->whereNull('hidden_at')
            ->with('user')
            ->latest('created_at')
            ->limit(self::CONTEXT_POSTS)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Build a plain-text transcript of the given posts (oldest first), labelled by
     * display name, for the model to reply to.
     *
     * @param Collection<int, Post> $posts
     */
    protected function buildTranscript(Collection $posts): string
    {
        $lines = [];

        foreach ($posts as $post) {
            $author = $post->user->display_name ?? 'Unknown';
            $text = trim((string) $post->content);

            if ($text !== '') {
                $lines[] = "{$author}: {$text}";
            }
        }

        return implode("\n\n", $lines);
    }
}

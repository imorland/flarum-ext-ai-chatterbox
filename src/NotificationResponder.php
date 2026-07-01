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
use Flarum\Notification\Notification;
use Flarum\Post\Post;
use IanM\AIChatterbox\Jobs\GenerateReplyJob;
use Illuminate\Contracts\Queue\Queue;

/**
 * Drives the bots' primary behaviour: respond to their notifications. Each tick
 * it reads every bot's unread notifications and, for the actionable ones (being
 * mentioned / replied to), enqueues a directed reply. Non-actionable types (e.g.
 * a like on the bot's post) are simply marked read. Everything it touches is
 * marked read so it's never processed twice.
 *
 * This runs *before* (and independently of) the frequency-limited new-content
 * roll, so mentions are always answered promptly.
 */
class NotificationResponder
{
    /**
     * Notification types that warrant a reply.
     *
     * Note the subject differs by type:
     *  - userMentioned: subject IS the post that mentioned the bot.
     *  - postMentioned: subject is the bot's OWN post that was replied to; the post
     *    that actually triggered it is the *reply* (from_user's post). We must
     *    respond to the reply, not the subject — otherwise it looks like a
     *    self-mention and gets skipped.
     */
    protected const REPLYABLE_TYPES = ['userMentioned', 'postMentioned'];

    /**
     * Safety cap on how many notifications to process in one tick.
     */
    protected const MAX_PER_TICK = 50;

    /**
     * Window (seconds) over which to scatter mention-replies. Spread over a few
     * minutes so replies arrive at human-varied delays — some quick, some a couple of
     * minutes later — rather than all at once. Still prompt enough to read as "saw the
     * ping and answered". Autonomous (non-mention) replies are scattered separately,
     * within the tick, by the scheduler.
     */
    protected const RESPONSE_SPREAD_SECONDS = 180;

    /**
     * Chance (percent) that a bot replies to a postMentioned notification triggered
     * by ANOTHER BOT replying to its post. Kept low so a little organic back-and-
     * forth survives without a runaway bot↔bot cascade. Human-triggered post
     * mentions and all user mentions are unaffected (always answered).
     */
    protected const BOT_POST_MENTION_REPLY_PERCENT = 10;

    public function __construct(
        protected Settings $settings,
        protected BotUserManager $bots,
        protected Queue $queue
    ) {
    }

    /**
     * Process unread bot notifications. Returns the number of replies enqueued.
     */
    public function run(): int
    {
        $botIds = $this->bots->query()->pluck('id')->all();

        if (empty($botIds)) {
            return 0;
        }

        $tagIds = $this->settings->enabledTags();

        $notifications = Notification::query()
            ->whereIn('user_id', $botIds)
            ->whereNull('read_at')
            ->where('is_deleted', false)
            ->orderBy('created_at')
            ->limit(self::MAX_PER_TICK)
            ->get();

        $enqueued = 0;

        // At most one directed reply per (bot, discussion) per tick; the rest stay
        // unread and are picked up next tick once this one has landed.
        $claimed = [];

        foreach ($notifications as $notification) {
            $handled = $this->handle($notification, $tagIds, $botIds, $claimed, $enqueued);

            // Mark read once handled (or once we've decided there's nothing to do).
            if ($handled) {
                $this->markRead($notification);
            }
        }

        return $enqueued;
    }

    /**
     * Decide what to do with a single notification. Returns true if it should be
     * marked read (i.e. we've dealt with it — whether by enqueueing a reply or by
     * intentionally ignoring it).
     *
     * @param int[]                 $tagIds
     * @param int[]                 $botIds
     * @param array<string, true>   $claimed (bot:discussion keys already enqueued this tick)
     */
    protected function handle(Notification $notification, array $tagIds, array $botIds, array &$claimed, int &$enqueued): bool
    {
        if (! in_array($notification->type, self::REPLYABLE_TYPES, true)) {
            // e.g. postLiked — nothing to respond to; mark read so it doesn't linger.
            return true;
        }

        // The post the bot should actually respond to — the post that mentioned/
        // replied to it (differs by notification type; see REPLYABLE_TYPES).
        $post = $this->mentioningPost($notification);

        // Subject gone, not a comment, or hidden — nothing to answer.
        if (! $post || $post->discussion === null || $post->hidden_at !== null) {
            return true;
        }

        $discussion = $post->discussion;

        // Only act within the configured tags and on public discussions.
        if ($discussion->is_private || ! $this->discussionInTags($discussion->id, $tagIds)) {
            return true;
        }

        // Don't answer a mention the bot made itself.
        if ($post->user_id === $notification->user_id) {
            return true;
        }

        // Per-type policy. The mention type determines whether this warrants a reply:
        //  - userMentioned: an explicit @-ping of the bot. Always reply (human or bot).
        //  - postMentioned: someone replied to / quoted the bot's post. From a HUMAN,
        //    always reply (be responsive to people). From a BOT, only reply rarely —
        //    otherwise two bots replying to each other's posts loop forever, which is
        //    the cascade that floods the forum. We mark it read either way so it isn't
        //    reconsidered next tick.
        if ($notification->type === 'postMentioned') {
            $fromBot = in_array((int) $post->user_id, array_map('intval', $botIds), true);

            if ($fromBot && mt_rand(1, 100) > self::BOT_POST_MENTION_REPLY_PERCENT) {
                return true; // skip (and mark read) — damps the bot↔bot cascade
            }
        }

        // If the bot already posted after the mentioning post, it has responded.
        $alreadyResponded = Post::query()
            ->where('discussion_id', $discussion->id)
            ->where('user_id', $notification->user_id)
            ->where('created_at', '>', $post->created_at)
            ->exists();

        if ($alreadyResponded) {
            return true;
        }

        $claimKey = $notification->user_id.':'.$discussion->id;

        if (isset($claimed[$claimKey])) {
            // Another notification this tick already enqueued a reply for this
            // (bot, discussion). Leave this one unread to handle next tick.
            return false;
        }

        // Scatter the reply across a short window instead of firing every mention-
        // reply this tick at the same instant. They're still answered within the
        // minute (prompt enough for a forum), but they trickle in like people typing
        // at their own pace rather than landing in one synchronised burst.
        $this->queue->later(mt_rand(0, self::RESPONSE_SPREAD_SECONDS), new GenerateReplyJob(
            (int) $notification->user_id,
            (int) $discussion->id,
            (int) $post->id
        ));

        $claimed[$claimKey] = true;
        $enqueued++;

        return true;
    }

    /**
     * Resolve the post that actually mentioned/replied to the bot — the one it
     * should respond to — accounting for the per-type subject difference.
     *
     *  - userMentioned: the subject IS that post.
     *  - postMentioned: the subject is the bot's own post; the triggering post is
     *    the reply, identified by the notifier (from_user_id) and the reply number
     *    in the data payload (or, failing that, their most recent post in the
     *    subject's discussion).
     */
    protected function mentioningPost(Notification $notification): ?Post
    {
        if ($notification->type === 'userMentioned') {
            return Post::query()->with('discussion')->find($notification->subject_id);
        }

        // postMentioned: locate the reply.
        $subject = Post::query()->find($notification->subject_id);

        if (! $subject) {
            return null;
        }

        $discussionId = $subject->discussion_id;
        $replyNumber = $notification->data['replyNumber'] ?? null;

        $query = Post::query()->with('discussion')
            ->where('discussion_id', $discussionId);

        if ($replyNumber !== null) {
            return $query->where('number', (int) $replyNumber)->first();
        }

        // Fallback: the notifier's most recent post in this discussion.
        if ($notification->from_user_id !== null) {
            return $query->where('user_id', $notification->from_user_id)
                ->latest('created_at')
                ->first();
        }

        return null;
    }

    /**
     * @param int[] $tagIds
     */
    protected function discussionInTags(int $discussionId, array $tagIds): bool
    {
        if (empty($tagIds)) {
            return false;
        }

        return Post::query()->getConnection()->table('discussion_tag')
            ->where('discussion_id', $discussionId)
            ->whereIn('tag_id', $tagIds)
            ->exists();
    }

    protected function markRead(Notification $notification): void
    {
        $notification->read_at = Carbon::now();
        $notification->save();
    }
}

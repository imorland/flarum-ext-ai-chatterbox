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

use Flarum\Post\Post;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use IanM\AIChatterbox\BotUserManager;
use IanM\AIChatterbox\ContentWriter;
use IanM\AIChatterbox\Settings;

/**
 * Likes a recent post within one of the configured tags, as a random bot user.
 * The like is applied through the JSON:API so flarum/likes' own logic, events
 * and permission checks run exactly as for a human like.
 */
class LikePostJob extends AbstractJob
{
    /**
     * How many recent posts to choose a like target from.
     */
    protected const CANDIDATE_POOL = 30;

    public function handle(
        Settings $settings,
        BotUserManager $bots,
        ContentWriter $writer
    ): void {
        if (! $settings->isOperable()) {
            return;
        }

        $actor = $bots->randomBot();

        if (! $actor) {
            return;
        }

        $post = $this->pickPost($settings->enabledTags(), $actor);

        if (! $post) {
            return;
        }

        // The likes API only attaches if not already liked, so this is idempotent.
        $writer->likePost($actor, $post->id);
    }

    /**
     * Pick a recent comment post in an enabled tag that the actor can see and did
     * not author. Scoped with whereVisibleTo so the chosen post is guaranteed to
     * be fetchable through the API as this actor.
     *
     * @param int[] $tagIds
     */
    protected function pickPost(array $tagIds, User $actor): ?Post
    {
        return Post::query()
            ->whereVisibleTo($actor)
            ->whereIn('type', ['comment'])
            ->where('user_id', '!=', $actor->id)
            ->whereExists(function ($query) use ($tagIds) {
                $query->selectRaw('1')
                    ->from('discussion_tag')
                    ->whereColumn('discussion_tag.discussion_id', 'posts.discussion_id')
                    ->whereIn('discussion_tag.tag_id', $tagIds);
            })
            ->latest('created_at')
            ->limit(self::CANDIDATE_POOL)
            ->get()
            ->shuffle()
            ->first();
    }
}

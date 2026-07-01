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

use Flarum\Api\Client;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\PostResource;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Creates discussions, replies and likes on behalf of a bot actor by driving
 * Flarum's internal JSON:API (not by writing Eloquent models directly). Going
 * through the API means every other extension's endpoint hooks, validation,
 * events and permission checks run exactly as they would for a human request.
 *
 * Creates use JsonApi::process (no existing model id needed). The like is an
 * update-by-id, which JsonApi::process can't route (it doesn't substitute the
 * {id} path segment), so it goes through Api\Client which routes the id properly.
 */
class ContentWriter
{
    public function __construct(
        protected JsonApi $api,
        protected Client $client
    ) {
    }

    /**
     * Create a new discussion (with its first post) tagged with the given tags.
     *
     * @param int[] $tagIds
     */
    public function createDiscussion(User $actor, string $title, string $body, array $tagIds): Discussion
    {
        $tagData = array_map(fn (int $id) => ['type' => 'tags', 'id' => (string) $id], $tagIds);

        /** @var Discussion $discussion */
        $discussion = $this->api
            ->forResource(DiscussionResource::class)
            ->forEndpoint('create')
            ->process([
                'data' => [
                    'attributes' => [
                        'title' => $title,
                        'content' => $body,
                    ],
                    'relationships' => [
                        'tags' => [
                            'data' => array_values($tagData),
                        ],
                    ],
                ],
            ], options: ['actor' => $actor]);

        return $discussion;
    }

    /**
     * Post a reply to an existing discussion.
     */
    public function createReply(User $actor, int $discussionId, string $body): Post
    {
        /** @var Post $post */
        $post = $this->api
            ->forResource(PostResource::class)
            ->forEndpoint('create')
            ->process([
                'data' => [
                    'attributes' => [
                        'content' => $body,
                    ],
                    'relationships' => [
                        'discussion' => [
                            'data' => [
                                'type' => 'discussions',
                                'id' => (string) $discussionId,
                            ],
                        ],
                    ],
                ],
            ], options: ['actor' => $actor]);

        return $post;
    }

    /**
     * Like a post (idempotent — the likes resource only attaches if not already
     * liked) by PATCHing the post's `isLiked` attribute. Uses Api\Client so the
     * post id is routed through the URI; JsonApi::process can't do update-by-id.
     */
    public function likePost(User $actor, int $postId): void
    {
        $this->client
            ->withActor($actor)
            ->withBody([
                'data' => [
                    'type' => 'posts',
                    'id' => (string) $postId,
                    'attributes' => [
                        'isLiked' => true,
                    ],
                ],
            ])
            ->patch("/posts/{$postId}");
    }
}

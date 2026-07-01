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

use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->default('ianm-ai-chatterbox.enabled', false)
        ->default('ianm-ai-chatterbox.model', 'gpt-4o-mini')
        ->default('ianm-ai-chatterbox.prompt', Settings::DEFAULT_PROMPT)
        ->default('ianm-ai-chatterbox.user_count', 3)
        ->default('ianm-ai-chatterbox.frequency', 4)
        ->default('ianm-ai-chatterbox.enable_discussions', true)
        ->default('ianm-ai-chatterbox.enable_replies', true)
        ->default('ianm-ai-chatterbox.enable_likes', true)
        ->default('ianm-ai-chatterbox.weight_discussions', 1)
        ->default('ianm-ai-chatterbox.weight_replies', 3)
        ->default('ianm-ai-chatterbox.weight_likes', 2)
        ->default('ianm-ai-chatterbox.active_start', '09:00')
        ->default('ianm-ai-chatterbox.active_end', '17:00')
        ->default('ianm-ai-chatterbox.simulate_typing', true)
        ->default('ianm-ai-chatterbox.delay_min', 3)
        ->default('ianm-ai-chatterbox.delay_max', 5)
        ->default('ianm-ai-chatterbox.feed_urls', Settings::DEFAULT_FEED_URLS)
        ->default('ianm-ai-chatterbox.news_share', 0.35)
        ->default('ianm-ai-chatterbox.conversation_continue_chance', 0.35)
        ->default('ianm-ai-chatterbox.conversation_max_depth', 6)
        ->default('ianm-ai-chatterbox.max_replies_per_thread', 2)
        ->default('ianm-ai-chatterbox.busy_thread_gate', true),

    (new Extend\Console())
        ->command(Console\TickCommand::class)
        ->schedule(Console\TickCommand::class, Console\TickSchedule::class),
];

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

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use IanM\AIChatterbox\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    protected function make(array $values): Settings
    {
        return new Settings(new ArrayRepository($values));
    }

    public function test_news_share_defaults_to_a_news_minority(): void
    {
        $this->assertSame(0.35, $this->make([])->newsShare());
    }

    public function test_news_share_is_clamped_to_zero_one(): void
    {
        $this->assertSame(0.0, $this->make(['ianm-ai-chatterbox.news_share' => -0.5])->newsShare());
        $this->assertSame(1.0, $this->make(['ianm-ai-chatterbox.news_share' => 2])->newsShare());
        $this->assertSame(0.6, $this->make(['ianm-ai-chatterbox.news_share' => 0.6])->newsShare());
    }

    public function test_conversation_continue_chance_defaults_and_clamps(): void
    {
        $this->assertSame(0.35, $this->make([])->conversationContinueChance());
        $this->assertSame(0.0, $this->make(['ianm-ai-chatterbox.conversation_continue_chance' => -1])->conversationContinueChance());
        $this->assertSame(1.0, $this->make(['ianm-ai-chatterbox.conversation_continue_chance' => 5])->conversationContinueChance());
        $this->assertSame(0.5, $this->make(['ianm-ai-chatterbox.conversation_continue_chance' => 0.5])->conversationContinueChance());
    }

    public function test_conversation_max_depth_defaults_and_floors_at_one(): void
    {
        $this->assertSame(6, $this->make([])->conversationMaxDepth());
        $this->assertSame(1, $this->make(['ianm-ai-chatterbox.conversation_max_depth' => 0])->conversationMaxDepth());
        $this->assertSame(1, $this->make(['ianm-ai-chatterbox.conversation_max_depth' => -3])->conversationMaxDepth());
        $this->assertSame(10, $this->make(['ianm-ai-chatterbox.conversation_max_depth' => 10])->conversationMaxDepth());
    }

    public function test_max_replies_per_thread_defaults_and_floors(): void
    {
        $this->assertSame(2, $this->make([])->maxRepliesPerThread());
        $this->assertSame(1, $this->make(['ianm-ai-chatterbox.max_replies_per_thread' => 0])->maxRepliesPerThread());
        $this->assertSame(8, $this->make(['ianm-ai-chatterbox.max_replies_per_thread' => 8])->maxRepliesPerThread());
    }

    public function test_busy_thread_gate_defaults_on_and_reads_bool(): void
    {
        $this->assertTrue($this->make([])->busyThreadGate());
        $this->assertFalse($this->make(['ianm-ai-chatterbox.busy_thread_gate' => false])->busyThreadGate());
        $this->assertTrue($this->make(['ianm-ai-chatterbox.busy_thread_gate' => true])->busyThreadGate());
    }

    public function test_within_active_hours_handles_a_same_day_window(): void
    {
        $settings = $this->make([
            'ianm-ai-chatterbox.active_start' => '09:00',
            'ianm-ai-chatterbox.active_end' => '17:00',
        ]);

        $this->assertTrue($settings->withinActiveHours(Carbon::parse('2026-06-30 09:00')));
        $this->assertTrue($settings->withinActiveHours(Carbon::parse('2026-06-30 13:30')));
        $this->assertFalse($settings->withinActiveHours(Carbon::parse('2026-06-30 17:00')), 'end is exclusive');
        $this->assertFalse($settings->withinActiveHours(Carbon::parse('2026-06-30 08:59')));
        $this->assertFalse($settings->withinActiveHours(Carbon::parse('2026-06-30 23:00')));
    }

    public function test_within_active_hours_handles_an_overnight_window(): void
    {
        $settings = $this->make([
            'ianm-ai-chatterbox.active_start' => '22:00',
            'ianm-ai-chatterbox.active_end' => '06:00',
        ]);

        $this->assertTrue($settings->withinActiveHours(Carbon::parse('2026-06-30 23:30')));
        $this->assertTrue($settings->withinActiveHours(Carbon::parse('2026-06-30 02:00')));
        $this->assertFalse($settings->withinActiveHours(Carbon::parse('2026-06-30 12:00')));
        $this->assertFalse($settings->withinActiveHours(Carbon::parse('2026-06-30 06:00')), 'end is exclusive');
    }

    public function test_equal_start_and_end_means_always_active(): void
    {
        $settings = $this->make([
            'ianm-ai-chatterbox.active_start' => '09:00',
            'ianm-ai-chatterbox.active_end' => '09:00',
        ]);

        $this->assertTrue($settings->withinActiveHours(Carbon::parse('2026-06-30 03:00')));
        $this->assertTrue($settings->withinActiveHours(Carbon::parse('2026-06-30 15:00')));
    }

    public function test_enabled_tags_decodes_a_json_array_of_ids(): void
    {
        $settings = $this->make([
            'ianm-ai-chatterbox.enabled_tags' => '[1, "2", 3]',
        ]);

        $this->assertSame([1, 2, 3], $settings->enabledTags());
    }

    public function test_enabled_tags_is_empty_when_unset_or_invalid(): void
    {
        $this->assertSame([], $this->make([])->enabledTags());
        $this->assertSame([], $this->make(['ianm-ai-chatterbox.enabled_tags' => 'not json'])->enabledTags());
    }

    public function test_is_operable_requires_enabled_key_users_and_tags(): void
    {
        $base = [
            'ianm-ai-chatterbox.enabled' => true,
            'ianm-ai-chatterbox.api_key' => 'sk-test',
            'ianm-ai-chatterbox.user_count' => 2,
            'ianm-ai-chatterbox.enabled_tags' => '[1]',
        ];

        $this->assertTrue($this->make($base)->isOperable());

        $this->assertFalse($this->make(['ianm-ai-chatterbox.enabled' => false] + $base)->isOperable());
        $this->assertFalse($this->make(['ianm-ai-chatterbox.api_key' => ''] + $base)->isOperable());
        $this->assertFalse($this->make(['ianm-ai-chatterbox.user_count' => 0] + $base)->isOperable());
        $this->assertFalse($this->make(['ianm-ai-chatterbox.enabled_tags' => '[]'] + $base)->isOperable());
    }
}

/**
 * Minimal in-memory settings repository for unit testing.
 */
class ArrayRepository implements SettingsRepositoryInterface
{
    public function __construct(protected array $values = [])
    {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        unset($this->values[$keyLike]);
    }
}

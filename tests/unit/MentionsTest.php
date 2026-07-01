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

use Flarum\User\DisplayName\UsernameDriver;
use Flarum\User\User;
use IanM\AIChatterbox\Mentions;
use PHPUnit\Framework\TestCase;

class MentionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // display_name resolves through a static driver set at app boot; in a unit
        // context wire the username-backed driver so display_name == username.
        User::setDisplayNameDriver(new UsernameDriver());
    }

    protected function user(int $id, string $displayName): User
    {
        $user = new User();
        $user->setRawAttributes(['id' => $id, 'username' => $displayName], true);

        return $user;
    }

    public function test_user_mention_uses_plain_username_form(): void
    {
        // Plain @username — the reliable form (the quoted @"name"#id form fails to
        // embed when the display name has spaces/punctuation).
        $this->assertSame('@alice', Mentions::user($this->user(5, 'alice')));
    }

    public function test_link_replaces_known_participant_plain_mention(): void
    {
        $alice = $this->user(5, 'alice');

        $out = Mentions::link('Thanks @alice, good point!', [$alice]);

        $this->assertSame('Thanks @alice, good point!', $out);
    }

    public function test_link_leaves_unknown_names_untouched(): void
    {
        $alice = $this->user(5, 'alice');

        $out = Mentions::link('Hi @Bob and @alice', [$alice]);

        $this->assertStringContainsString('@Bob', $out);
        $this->assertStringContainsString('@alice', $out);
    }

    public function test_link_does_not_double_link_existing_user_mentions(): void
    {
        $alice = $this->user(5, 'alice');

        // An already-linked quoted mention must be left alone (the negative
        // lookahead for '#' protects it).
        $already = 'See @"Alice"#5 above';
        $this->assertSame($already, Mentions::link($already, [$alice]));
    }

    public function test_link_prefers_longer_names_first(): void
    {
        $anna = $this->user(1, 'anna');
        $annabel = $this->user(2, 'annabel');

        $out = Mentions::link('Hey @annabel!', [$anna, $annabel]);

        // The longer username should win, not the "anna" prefix.
        $this->assertSame('Hey @annabel!', $out);
        $this->assertStringNotContainsString('@anna!', $out);
    }
}

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

use Flarum\Post\Post;
use Flarum\User\User;

/**
 * Builds and links Flarum mention syntax in generated content.
 *
 * flarum/mentions parses mentions from a post's raw content at save time. It
 * accepts a plain `@username` for user mentions (regex: `\B@[a-z0-9_-]+(?!#)`),
 * which is far more robust than the quoted `@"Display Name"#<id>` form — the
 * quoted form silently fails to embed whenever the display name contains a space
 * or punctuation (common with nicknames and real users). Usernames are always
 * `[a-z0-9_-]`-safe, so we emit:
 *   - user mention: @username        (plain — the reliable form)
 *   - post mention: @"Display Name"#p<postId>   (no plain syntax exists for posts)
 *
 * The model writes a plain `@Name` using whatever name it sees; we substitute the
 * canonical `@username` for any participant we recognise (by display name OR
 * username), so the parser embeds it reliably.
 */
class Mentions
{
    /**
     * Build a user mention string — the plain, reliable `@username` form.
     */
    public static function user(User $user): string
    {
        return '@'.$user->username;
    }

    /**
     * Build a post mention string (references the post's author display name). Post
     * mentions have no plain form, so this keeps the quoted `#p<id>` syntax; the ID
     * is authoritative and we build it from a real post, so it embeds correctly.
     */
    public static function post(Post $post): string
    {
        $name = self::cleanName($post->user->display_name ?? 'user');

        return '@"'.$name.'"#p'.$post->id;
    }

    /**
     * Replace plain `@Name` references (the model writes the display name, but it
     * may also use the username) with the canonical `@username` mention for each
     * known participant. Longest names first so e.g. "Anna Lee" is matched before
     * "Anna". Already-linked mentions (a quoted name, or `@x#…`) are left alone.
     *
     * @param User[] $participants
     */
    public static function link(string $text, array $participants): string
    {
        foreach ($participants as $user) {
            // Candidate spellings the model might have written for this user, longest
            // first so a longer name isn't shadowed by a shorter substring.
            $aliases = array_filter([
                (string) $user->display_name,
                (string) $user->username,
            ], fn (string $n) => $n !== '');

            $aliases = array_unique($aliases);
            usort($aliases, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

            foreach ($aliases as $alias) {
                // Match "@Alias" not already part of a linked mention (no quote after
                // @, not immediately followed by '#...'). \B-style lookbehind keeps it
                // from matching mid-word; trailing \b stops partial-name matches.
                $pattern = '/(?<![\w"])@'.preg_quote($alias, '/').'\b(?!["#])/u';

                $text = preg_replace($pattern, self::user($user), $text);
            }
        }

        return $text;
    }

    /**
     * Strip any sequence that would break the mention regex from a display name
     * (mirrors the extension's own getCleanDisplayName cleanup).
     */
    protected static function cleanName(string $name): string
    {
        // Remove patterns like "#u123 that the parser uses as a mention terminator,
        // and the surrounding quote char which would close the mention early.
        $name = preg_replace('/"#[a-z]{0,3}[0-9]+/i', '', $name);

        return str_replace('"', '', (string) $name);
    }
}

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
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Http\DeveloperAccessToken;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Owns the lifecycle of the AI "bot" user accounts: a dedicated group to hold
 * them (so admins can identify and scope them), the permissions that group needs
 * to post, lazy creation of the accounts up to the configured count, and a
 * never-expiring developer access token per bot.
 */
class BotUserManager
{
    /**
     * Settings key under which the auto-created group's ID is cached.
     */
    public const GROUP_SETTING = 'ianm-ai-chatterbox.group_id';

    /**
     * Target number of bots whose persona should cover each enabled tag, so a
     * section always has someone who'll start and answer its threads. A tag with
     * fewer than this many interested bots is "uncovered" and gets healed.
     */
    protected const MIN_COVER_PER_TAG = 2;

    /**
     * Most bots to re-persona for coverage in a single tick — throttles API cost and
     * persona churn so adding a new tag converges over a few ticks, not in one burst.
     */
    protected const MAX_RECOVER_PER_TICK = 2;

    /**
     * Global permissions the bot group needs so the JSON:API lets it post.
     * Includes "without approval" so content goes live immediately when
     * flarum/approval is enabled. Liking is governed by post visibility (no
     * permission gate), so it isn't listed here.
     *
     * @var string[]
     */
    protected const GROUP_PERMISSIONS = [
        'startDiscussion',
        'reply',
        'discussion.startWithoutApproval',
        'discussion.replyWithoutApproval',
        // fof/terms (optional): lets bots postpone/bypass policy acceptance so the
        // terms group processor doesn't reduce them to guests. Harmless no-op row
        // if fof/terms isn't installed.
        'fof-terms.postpone-policies-accept',
    ];

    /**
     * Abilities that must be granted *per restricted tag* so the bots can see,
     * start discussions in, and reply (without approval) within tags the admin
     * selected. Stored as `tag{id}.{ability}` rows, matching flarum/tags' and
     * flarum/approval's own permission grids. Unrestricted tags fall through to
     * the global permissions above.
     *
     * @var string[]
     */
    protected const TAG_ABILITIES = [
        'viewForum',
        'startDiscussion',
        'discussion.reply',
        'discussion.startWithoutApproval',
        'discussion.replyWithoutApproval',
    ];

    public function __construct(
        protected Settings $settings,
        protected SettingsRepositoryInterface $rawSettings,
        protected OpenAIClient $client,
        protected Cache $cache
    ) {
    }

    /**
     * Ensure the bot group exists and that the configured number of bot users
     * have been created. Safe to call on every scheduler tick — it only creates
     * what is missing.
     */
    public function ensure(): void
    {
        $group = $this->group();

        // Reconcile permissions every tick so an existing group (or newly-selected
        // restricted tags) self-heals without recreating anything.
        $this->grantPermissions($group);
        $this->grantTagPermissions($group);

        $this->createMissingBots($group);

        // Give any pre-existing bots that predate personalities a persona too, so the
        // whole cast is personalised rather than a mix of named members and AI_User_N.
        $this->backfillPersonas();

        // Self-heal coverage: if a tag was enabled after the cast was built, no bot
        // will be interested in it, so its threads go unanswered. Re-persona a few
        // bots each tick to fill any gap, so new tags get covered within a few ticks.
        $this->healTagCoverage();
    }

    /**
     * Create bot accounts up to the configured user_count.
     *
     * Guarded so it never overshoots the target: creating each bot makes a (slow)
     * persona-generation API call, so the whole loop can outlive a single scheduler
     * tick — and the scheduler's withoutOverlapping mutex can expire mid-run, letting
     * the next tick start a second creation pass from a stale count and double-create.
     * A dedicated lock ensures only one creation pass runs at a time, and the live
     * count is re-checked each iteration so creation stops the instant the target is
     * reached (by this pass or any other).
     */
    protected function createMissingBots(Group $group): void
    {
        $target = $this->settings->userCount();

        if ($this->query()->count() >= $target) {
            return;
        }

        // Non-blocking: if another pass holds the lock, skip — it's already creating.
        $lock = $this->cache->lock('ianm-ai-chatterbox.create-bots', 600);

        if (! $lock->get()) {
            return;
        }

        try {
            // Re-read the live count each iteration so we never create past the target,
            // even if accounts appear concurrently.
            while (($existing = $this->query()->count()) < $target) {
                $this->createBot($existing + 1, $group);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Ensure every enabled tag has at least MIN_COVER_PER_TAG bots interested in it.
     * For any uncovered tag (e.g. one enabled after the cast was built), re-persona a
     * throttled number of existing bots to cover it — picking bots that aren't the
     * sole coverer of some other tag, so healing one gap doesn't open another.
     */
    protected function healTagCoverage(): void
    {
        if ($this->settings->apiKey() === '') {
            return;
        }

        $bots = $this->query()->whereNotNull('ai_persona')->get();

        if ($bots->isEmpty()) {
            return;
        }

        // Map each enabled tag -> the bots whose interests already cover it.
        $tags = Tag::query()->whereIn('id', $this->settings->enabledTags())->get();

        // coverers[tagId] = list of bot ids interested in that tag
        $coverers = [];
        foreach ($tags as $tag) {
            $tagWords = $this->keywords($tag->name.' '.(string) $tag->description);
            $coverers[(int) $tag->id] = [];

            foreach ($bots as $bot) {
                $persona = $this->personaFor($bot);
                $interestWords = $this->keywords(($persona['interests'] ?? '').' '.($persona['bio'] ?? ''));

                if (! empty(array_intersect($tagWords, $interestWords))) {
                    $coverers[(int) $tag->id][] = (int) $bot->id;
                }
            }
        }

        // Bots that are the ONLY coverer of some tag — don't repurpose these, or we
        // just move the gap around.
        $soleCoverers = [];
        foreach ($coverers as $botIds) {
            if (count($botIds) === 1) {
                $soleCoverers[$botIds[0]] = true;
            }
        }

        $recovered = 0;

        foreach ($tags as $tag) {
            if ($recovered >= self::MAX_RECOVER_PER_TICK) {
                break;
            }

            $have = count($coverers[(int) $tag->id]);

            if ($have >= self::MIN_COVER_PER_TAG) {
                continue; // already covered
            }

            // Candidates to repurpose: bots not already interested in this tag and not
            // the sole coverer of another tag.
            $candidates = $bots->filter(function (User $b) use ($coverers, $tag, $soleCoverers) {
                $id = (int) $b->id;

                return ! in_array($id, $coverers[(int) $tag->id], true) && ! isset($soleCoverers[$id]);
            })->shuffle();

            foreach ($candidates as $bot) {
                if ($recovered >= self::MAX_RECOVER_PER_TICK || $have >= self::MIN_COVER_PER_TAG) {
                    break;
                }

                $topic = trim((string) $tag->description) !== ''
                    ? "{$tag->name} ({$tag->description})"
                    : (string) $tag->name;

                $persona = $this->client->generatePersona($this->tagSummaries(), $this->existingPersonaDescriptors(), $topic);
                $this->applyPersona($bot, $persona);

                $have++;
                $recovered++;
            }
        }
    }

    /**
     * Reduce text to lower-cased significant keyword tokens for cheap relevance
     * matching (drops short and generic words). Used to decide, without an API call,
     * whether a persona's interests already cover a tag.
     *
     * @return string[]
     */
    protected function keywords(string $text): array
    {
        $stop = ['the', 'and', 'for', 'with', 'about', 'this', 'that', 'are', 'section',
            'discussions', 'discussion', 'forum', 'general', 'corner', 'talk', 'chat',
            'topic', 'topics', 'more', 'here', 'anything', 'everything', 'stuff'];

        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];

        $words = array_filter($words, function (string $w) use ($stop) {
            return mb_strlen($w) >= 4 && ! in_array($w, $stop, true) && ! ctype_digit($w);
        });

        return array_values(array_unique($words));
    }

    /**
     * Generate and apply a persona to every bot that doesn't have one yet. Runs each
     * missing bot through persona generation; skips silently if the API isn't
     * configured (the bots keep their current identity until it is).
     */
    protected function backfillPersonas(): void
    {
        if ($this->settings->apiKey() === '') {
            return;
        }

        $missing = $this->query()->whereNull('ai_persona')->get();

        // Topics the cast should collectively cover (the enabled tags). We assign each
        // new persona one to be genuinely into, rotating so coverage stays even — this
        // guarantees every section has members who'll start and answer its threads,
        // while individuals stay distinct and rounded.
        $coverTopics = $this->coverageTopics();
        $cursor = $this->query()->whereNotNull('ai_persona')->count();

        foreach ($missing as $bot) {
            $topic = empty($coverTopics) ? '' : $coverTopics[$cursor % count($coverTopics)];
            $cursor++;

            // Rebuild the descriptor list each iteration so each new persona is varied
            // against the ones just generated this run, not only pre-existing ones.
            $persona = $this->client->generatePersona($this->tagSummaries(), $this->existingPersonaDescriptors(), $topic);
            $this->applyPersona($bot, $persona);
        }
    }

    /**
     * The topics the persona cast should collectively cover, derived from the enabled
     * tags' names + descriptions. Fully dynamic — whatever tags the forum has.
     *
     * @return string[]
     */
    protected function coverageTopics(): array
    {
        $tagIds = $this->settings->enabledTags();

        if (empty($tagIds)) {
            return [];
        }

        return Tag::query()->whereIn('id', $tagIds)->get()
            ->map(function (Tag $t) {
                $desc = trim((string) $t->description);

                return $desc !== '' ? "{$t->name} ({$desc})" : (string) $t->name;
            })
            ->values()
            ->all();
    }

    /**
     * Grant the per-tag abilities the bot group needs for each enabled,
     * restricted tag (and its ancestors, since flarum/tags requires the parent's
     * permission too). Unrestricted tags need nothing extra. Idempotent.
     */
    protected function grantTagPermissions(Group $group): void
    {
        $tagIds = $this->settings->enabledTags();

        if (empty($tagIds)) {
            return;
        }

        $tags = Tag::query()->whereIn('id', $tagIds)->get();

        foreach ($tags as $tag) {
            // Walk up the parent chain; a restricted ancestor also needs granting.
            for ($current = $tag; $current !== null; $current = $current->parent) {
                if (! $current->is_restricted) {
                    continue;
                }

                foreach (self::TAG_ABILITIES as $ability) {
                    $this->ensurePermission($group->id, "tag{$current->id}.{$ability}");
                }
            }
        }
    }

    /**
     * All bot users (members of the AI Users group).
     *
     * @return Builder<User>
     */
    public function query(): Builder
    {
        $groupId = $this->groupId();

        return User::whereHas('groups', function ($q) use ($groupId) {
            $q->where('groups.id', $groupId);
        });
    }

    /**
     * Pick a random bot user to act as the actor for a generated action, or null
     * if none exist yet.
     */
    public function randomBot(): ?User
    {
        return $this->query()->inRandomOrder()->first();
    }

    /**
     * Resolve (creating if necessary) the dedicated "AI Users" group.
     */
    public function group(): Group
    {
        $groupId = $this->rawSettings->get(self::GROUP_SETTING);

        if ($groupId && ($group = Group::find($groupId))) {
            return $group;
        }

        $group = new Group();
        $group->name_singular = 'AI User';
        $group->name_plural = 'AI Users';
        $group->color = '#8b5cf6';
        $group->icon = 'fas fa-robot';
        $group->save();

        $this->rawSettings->set(self::GROUP_SETTING, $group->id);

        return $group;
    }

    /**
     * Grant the permissions the bot group needs to create discussions and
     * replies through the API. Idempotent.
     */
    protected function grantPermissions(Group $group): void
    {
        foreach (self::GROUP_PERMISSIONS as $permission) {
            $this->ensurePermission($group->id, $permission);
        }
    }

    /**
     * Grant a single permission to a group if it isn't already granted.
     */
    protected function ensurePermission(int $groupId, string $permission): void
    {
        $exists = Permission::where('group_id', $groupId)
            ->where('permission', $permission)
            ->exists();

        if ($exists) {
            return;
        }

        Permission::unguarded(function () use ($groupId, $permission) {
            Permission::create([
                'group_id' => $groupId,
                'permission' => $permission,
            ]);
        });
    }

    protected function groupId(): ?int
    {
        $id = $this->rawSettings->get(self::GROUP_SETTING);

        return $id ? (int) $id : null;
    }

    protected function createBot(int $index, Group $group): User
    {
        // Generate a persona first (when the API is configured) so the account can be
        // created directly with its real display name; fall back to AI_User_N otherwise.
        // Seed it to cover one forum topic (rotating by index) so the cast collectively
        // covers every section.
        $coverTopics = $this->coverageTopics();
        $coverTopic = empty($coverTopics) ? '' : $coverTopics[($index - 1) % count($coverTopics)];

        $persona = $this->settings->apiKey() !== ''
            ? $this->client->generatePersona($this->tagSummaries(), $this->existingPersonaDescriptors(), $coverTopic)
            : null;

        $username = $this->resolveUsername($persona['display_name'] ?? '', $index);

        $user = new User();
        $user->username = $username;
        $user->email = "ai-user-{$index}-".Str::lower(Str::random(8)).'@ai-users.invalid';
        $user->password = Str::random(32);
        $user->joined_at = Carbon::now();
        $user->activate(); // marks the email confirmed so the account is usable
        $user->save();

        $user->groups()->sync([$group->id]);

        // Mint a never-expiring developer access token for the bot so it has a
        // real authenticated identity (usable for direct API calls if needed).
        DeveloperAccessToken::generate($user->id);

        if ($persona !== null) {
            $this->applyPersona($user, $persona);
        }

        return $user;
    }

    /**
     * Read a bot's stored persona, or null if it has none / it's unreadable.
     *
     * @return array<string, string>|null
     */
    public function personaFor(User $user): ?array
    {
        $raw = $user->getAttribute('ai_persona');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persist a persona onto a bot: store the canonical JSON in ai_persona, and —
     * only where the columns exist on this forum — set the display name and bio so
     * the member reads as a real person. Portable: a forum without fof/user-bio (no
     * `bio` column) simply skips the bio.
     *
     * Returns false (persisting nothing) when the persona is unusable — e.g. an empty
     * generation result. Leaving ai_persona null means the bot is retried on a later
     * tick rather than getting stuck with a half-applied/placeholder identity.
     *
     * @param array<string, string> $persona
     */
    protected function applyPersona(User $user, array $persona): bool
    {
        if (! $this->personaIsUsable($persona)) {
            return false;
        }

        $user->setAttribute('ai_persona', json_encode($persona));

        // Display name: prefer a unique username derived from the persona. We only
        // rename if we can find a free, valid handle; otherwise keep the current one.
        $displayName = $this->resolveUsername($persona['display_name'] ?? '', null, (int) $user->id);
        if ($displayName !== null && $displayName !== $user->username) {
            $user->username = $displayName;
        }

        // Bio (fof/user-bio) — set only when the column is present.
        if (! empty($persona['bio']) && $this->usersHasColumn('bio')) {
            $user->setAttribute('bio', $persona['bio']);
        }

        $user->save();

        return true;
    }

    /**
     * A persona is usable only if generation actually produced content — at minimum a
     * display name and some interests. Empty/failed generations are rejected so they
     * aren't persisted as "done".
     *
     * @param array<string, string> $persona
     */
    protected function personaIsUsable(array $persona): bool
    {
        return trim($persona['display_name'] ?? '') !== ''
            && trim($persona['interests'] ?? '') !== '';
    }

    /**
     * "Name — description" lines for the enabled tags, to seed personas toward what
     * the forum actually discusses.
     *
     * @return string[]
     */
    protected function tagSummaries(): array
    {
        $tagIds = $this->settings->enabledTags();

        if (empty($tagIds)) {
            return [];
        }

        return Tag::query()->whereIn('id', $tagIds)->get()
            ->map(function (Tag $t) {
                $desc = trim((string) $t->description);

                return $desc !== '' ? "{$t->name} — {$desc}" : $t->name;
            })
            ->all();
    }

    /**
     * Short "name: interests (tone)" descriptors of the existing bot cast, so the
     * model can make each new persona genuinely different — in interests and voice,
     * not just name. Falls back to just the username when a bot has no persona yet.
     *
     * @return string[]
     */
    protected function existingPersonaDescriptors(): array
    {
        return $this->query()->get(['username', 'ai_persona'])
            ->map(function (User $u) {
                $persona = $this->personaFor($u);

                if ($persona === null) {
                    return (string) $u->username;
                }

                $interests = trim((string) ($persona['interests'] ?? ''));
                $tone = trim((string) ($persona['tone'] ?? ''));

                $line = (string) $u->username;
                if ($interests !== '') {
                    $line .= ': '.$interests;
                }
                if ($tone !== '') {
                    $line .= ' ('.$tone.')';
                }

                return $line;
            })
            ->all();
    }

    /**
     * Turn a persona's desired display name into a valid, unique, non-colliding
     * username. Sanitises to what Flarum allows, ensures uniqueness, and falls back
     * to AI_User_{index} when nothing usable remains.
     *
     * @param int|null $index  Sequence number for the AI_User_N fallback.
     * @param int|null $selfId User id to exclude from the collision check (when renaming).
     */
    protected function resolveUsername(string $desired, ?int $index = null, ?int $selfId = null): ?string
    {
        // Flarum usernames allow letters, numbers, underscores and dashes.
        $base = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($desired)) ?? '';
        $base = trim($base, '_-');

        if ($base === '' || mb_strlen($base) < 3) {
            if ($index === null) {
                return null; // renaming an existing bot and the persona name is unusable
            }
            $base = "AI_User_{$index}";
        }

        $base = mb_substr($base, 0, 30);
        $candidate = $base;
        $suffix = 0;

        while ($this->usernameTaken($candidate, $selfId)) {
            $suffix++;
            $candidate = mb_substr($base, 0, 27).'_'.$suffix;
        }

        return $candidate;
    }

    /**
     * Whether the users table has a given column, checked via the model's own DB
     * connection (no Schema facade, so it works in any context including queued
     * jobs and bootstrapped scripts).
     */
    protected function usersHasColumn(string $column): bool
    {
        return User::query()->getConnection()
            ->getSchemaBuilder()
            ->hasColumn('users', $column);
    }

    protected function usernameTaken(string $username, ?int $selfId): bool
    {
        $query = User::where('username', $username);

        if ($selfId !== null) {
            $query->where('id', '!=', $selfId);
        }

        return $query->exists();
    }
}

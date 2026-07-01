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

use OpenAI;
use RuntimeException;

/**
 * Thin wrapper around the OpenAI PHP client. All model interaction goes through
 * here so a different provider can be dropped in by swapping this one class.
 *
 * Returns plain content; callers are responsible for persisting it. Every method
 * requires a configured API key and throws if none is set, so callers should
 * gate on {@see Settings::isOperable()} first.
 */
class OpenAIClient
{
    /**
     * Formatting guidance appended to every generation. Flarum renders Markdown,
     * but not every feature — tables in particular are unsupported.
     */
    protected const MARKDOWN_GUIDANCE = 'You may use Markdown that Flarum supports: bold, '
        .'italics, links, bullet and numbered lists, blockquotes, inline code and fenced code '
        .'blocks, and headings. Do NOT use Markdown tables — they are not supported. Keep '
        .'formatting light and natural, as a real forum member would.';

    /**
     * Anti-sameness guidance appended to every post/reply. LLMs default to a narrow
     * band of openers and filler ("I stumbled across…", "I just read…", "Has anyone
     * else…", "In today's fast-paced world…") that makes every bot sound identical.
     * This pushes for genuine variety and the author's own voice instead.
     */
    protected const STYLE_GUIDANCE = "Write like a real, specific person — not like an "
        ."assistant or a press release. Sound like yourself, in your own voice, however that "
        ."is.\n\n"
        ."Avoid the tired openers and filler that make forum posts sound generated. Do NOT "
        ."begin with any of: \"I stumbled across\", \"I just read\", \"I came across\", \"I "
        ."recently saw\", \"Has anyone else\", \"So I was thinking\", \"In today's world\", "
        ."\"In this day and age\", \"Let's dive in\", \"As we all know\". Don't open by naming "
        ."the topic and announcing you find it interesting. Just start somewhere real — a "
        ."reaction, a detail, an opinion, a half-finished thought — the way a person actually "
        ."would.\n\n"
        ."Vary your sentence length and rhythm. It's fine to be a bit informal, blunt, funny, "
        ."uncertain, or to ramble slightly — real people aren't polished. Don't end every post "
        ."with a tidy question inviting others to share unless it genuinely fits.";

    public function __construct(protected Settings $settings)
    {
    }

    /**
     * Invent a distinct personality for one bot, seeded by the forum's own tags so
     * the cast feels native to the community. Fully dynamic — no hardcoded persona
     * lists. Returns a structured persona the bot's identity (display name, bio) and
     * writing voice are built from.
     *
     * @param string[] $tagSummaries  "Name — description" lines for the forum's tags,
     *                                 used as loose backdrop (the community this person
     *                                 happens to belong to) — NOT a mandate to make every
     *                                 member obsessed with the busiest tags.
     * @param string[] $existingPersonas  Short "name: interests (tone)" descriptors of the
     *                                 cast so far, so each new member is deliberately
     *                                 different in interests, voice and background.
     * @param string   $coverTopic     A specific forum topic this member should genuinely
     *                                 be into (so the cast AS A WHOLE covers every section),
     *                                 while still being a distinct individual. Empty = free.
     *
     * @return array{display_name: string, bio: string, tone: string, interests: string, quirks: string}
     */
    public function generatePersona(array $tagSummaries = [], array $existingPersonas = [], string $coverTopic = ''): array
    {
        $system = 'You design believable, distinct online-forum member personas. Invent ONE '
            .'member who feels like a specific real individual — as varied as people on a real '
            .'forum are. Vary it widely across the whole cast: different ages, life stages, jobs '
            .'or studies, regions, and communication styles (some terse, some chatty, some dry, '
            .'some warm, some formal, some playful). Give them a realistic display name (a handle '
            .'a real person might pick — never containing "AI", "bot" or "user"), a short '
            .'first-person bio (1-2 sentences), a writing tone, a few interests, and one or two '
            .'harmless voice quirks.'."\n\n"
            .'Make the member a believable individual, not a caricature: real people have a few '
            .'interests, not one obsession. Give them a primary interest plus a couple of unrelated '
            .'side interests so they feel rounded and distinct from the rest of the cast.'."\n\n"
            .'Respond ONLY with minified JSON, no prose, with exactly these string keys: '
            .'{"display_name": "...", "bio": "...", "tone": "...", "interests": "...", "quirks": "..."}.';

        $context = '';

        if (trim($coverTopic) !== '') {
            // The cast must collectively cover every forum section, so this member is
            // seeded to genuinely care about a specific topic — someone who would
            // actually start and answer threads about it — while staying a distinct,
            // rounded individual (not defined solely by it).
            $context .= "This member should be someone genuinely interested in: {$coverTopic}. "
                ."Make that a real, central part of who they are (so they'd happily discuss it), "
                ."but still give them a couple of other unrelated interests and their own distinct "
                ."personality.\n\n";
        }

        if (!empty($tagSummaries)) {
            $context .= "For wider context, the forum's sections are:\n- "
                .implode("\n- ", $tagSummaries)."\n\n";
        }
        if (!empty($existingPersonas)) {
            $context .= "The cast so far (make this new member clearly DIFFERENT from these — "
                ."different interests, tone and background, and a distinct name):\n- "
                .implode("\n- ", $existingPersonas)."\n\n";
        }

        $user = $context.'Invent the new member now, distinct from everyone above.';

        $content = $this->complete($system, $user);

        $decoded = json_decode($this->extractJson($content), true);

        $persona = [
            'display_name' => '',
            'bio' => '',
            'tone' => '',
            'interests' => '',
            'quirks' => '',
        ];

        if (is_array($decoded)) {
            foreach (array_keys($persona) as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    $persona[$key] = trim($decoded[$key]);
                }
            }
        }

        return $persona;
    }

    /**
     * Ask the model to route news to tags: given candidate news items and the
     * forum's enabled tags (name + description only), pick the single best (item,
     * tag) pairing, or none if nothing genuinely fits any tag. Fully dynamic — the
     * model reasons from the tag descriptions, so it works with any forum's tags and
     * needs no hardcoded names or maps.
     *
     * @param array<int, array{title: string, summary: string, link: string}> $items
     *        Candidate news items, indexed 0..n.
     * @param array<int, array{id: int, name: string, description: string}> $tags
     *        Enabled (primary) tags to route into.
     * @param string[] $recentTitles
     *        Recent discussion titles, so the model can favour variety and avoid
     *        re-covering subjects (or sections) the forum has just discussed.
     *
     * @return array{tagId: int, itemIndex: int}|null
     *         The chosen pairing, or null when nothing fits well (invent a topic).
     */
    public function classifyItemToTag(array $items, array $tags, array $recentTitles = []): ?array
    {
        if (empty($items) || empty($tags)) {
            return null;
        }

        $tagLines = [];
        foreach ($tags as $tag) {
            $desc = trim($tag['description']) !== '' ? " — {$tag['description']}" : '';
            $tagLines[] = "Tag {$tag['id']}: \"{$tag['name']}\"{$desc}";
        }

        $itemLines = [];
        foreach ($items as $i => $item) {
            $summary = $item['summary'] !== '' ? " — {$item['summary']}" : '';
            $itemLines[] = "Item {$i}: \"{$item['title']}\"{$summary}";
        }

        $system = 'You route news stories to the forum section (tag) they best fit. '
            .'You are given a list of tags (each with a name and description) and a list of '
            .'candidate news items. Choose the SINGLE best news item to start a discussion about, '
            .'and the tag whose description it most genuinely belongs in. Only pair an item with a '
            .'tag if it is a real topical fit for that section — do not force an item into a tag it '
            .'does not suit. Favour variety: a healthy forum has discussions across all its '
            .'sections, so prefer an item that opens up a section or subject the recent threads '
            .'have not already covered, rather than piling onto whatever topic is currently busiest. '
            .'If no item is a good fit for any tag, return none.'."\n\n"
            .'Respond ONLY with minified JSON, no prose: '
            .'{"tagId": <tag id>, "itemIndex": <item index>} when there is a good pairing, '
            .'or {"tagId": null, "itemIndex": null} when nothing fits.';

        $user = "Tags:\n".implode("\n", $tagLines)."\n\nNews items:\n".implode("\n", $itemLines);

        if (!empty($recentTitles)) {
            $user .= "\n\nRecent discussions (avoid re-covering these subjects, and lean toward "
                ."sections they don't already represent):\n- ".implode("\n- ", $recentTitles);
        }

        $content = $this->complete($system, $user);

        $decoded = json_decode($this->extractJson($content), true);

        // isset() is false for null values too, so a "nothing fits" response of
        // {"tagId": null, "itemIndex": null} short-circuits here to a null result.
        if (!is_array($decoded) || !isset($decoded['tagId'], $decoded['itemIndex'])) {
            return null;
        }

        $tagId = (int) $decoded['tagId'];
        $itemIndex = (int) $decoded['itemIndex'];

        // Validate against what we actually sent — guard hallucinated ids/indexes.
        $validTagIds = array_map(fn (array $t) => $t['id'], $tags);

        if (!in_array($tagId, $validTagIds, true) || !isset($items[$itemIndex])) {
            return null;
        }

        return ['tagId' => $tagId, 'itemIndex' => $itemIndex];
    }

    /**
     * Build the "you are this member" guidance from a persona, appended to the base
     * prompt so each bot writes in its own voice. Affects HOW the bot writes only —
     * topic routing is decided elsewhere and is not influenced by the persona.
     * Returns '' when there's no persona (falls back to the shared voice).
     *
     * @param array<string, string>|null $persona
     */
    protected function personaSystemFragment(?array $persona): string
    {
        if (empty($persona)) {
            return '';
        }

        $parts = [];

        if (!empty($persona['display_name'])) {
            $parts[] = "You are posting as \"{$persona['display_name']}\", a regular member of this forum.";
        }
        if (!empty($persona['bio'])) {
            $parts[] = "About you: {$persona['bio']}";
        }
        if (!empty($persona['interests'])) {
            $parts[] = "You're especially into: {$persona['interests']}.";
        }
        if (!empty($persona['tone'])) {
            $parts[] = "Your writing tone: {$persona['tone']}.";
        }
        if (!empty($persona['quirks'])) {
            $parts[] = "Voice quirks to keep consistent: {$persona['quirks']}.";
        }

        if (empty($parts)) {
            return '';
        }

        return "\n\nStay in character as this specific member throughout (this shapes only your "
            ."voice and what you choose to say — not the forum's rules or the thread's topic):\n"
            .implode(' ', $parts);
    }

    /**
     * Pick a random "angle" for a generation so structure varies post-to-post rather
     * than everyone defaulting to the same shape. This is the strongest lever against
     * sameness: even with the same persona and topic, the opening move differs.
     *
     * @param 'discussion'|'reply' $kind
     */
    protected function varietyDirective(string $kind): string
    {
        $discussionAngles = [
            'Open with a strong opinion or hot take, then back it up.',
            'Start mid-thought, as if continuing a conversation already in your head.',
            'Lead with a short personal anecdote or something that happened to you.',
            'Open with a blunt question you genuinely want answered — no preamble.',
            'Start with a small confession, gripe, or admission.',
            'Open with a specific detail or example, not a general statement.',
            'Be a bit contrarian — push back on the obvious or popular view.',
            'Start casual and understated, like you almost didn\'t bother posting.',
            'Lead with something that surprised, annoyed, or delighted you.',
            'Open with a quick observation, then let it unfold.',
        ];

        $replyAngles = [
            'React to one specific thing someone said, not the thread in general.',
            'Agree, but add a twist, caveat, or example of your own.',
            'Politely disagree and say why.',
            'Build on the last point with a tangent that\'s still on-topic.',
            'Be brief — a sentence or two is plenty here.',
            'Share a relevant bit of your own experience.',
            'Ask a pointed follow-up question about something specific.',
            'Add a touch of humour or a light aside.',
            'Answer plainly and directly, no hedging.',
            'Bring a concrete detail or fact that moves the conversation along.',
        ];

        $pool = $kind === 'reply' ? $replyAngles : $discussionAngles;

        return $pool[array_rand($pool)];
    }

    /**
     * Pick a random KIND of spontaneous (non-news) thread, so self-started topics
     * span the range a real member posts — not just earnest discussion prompts.
     * Includes jokes, questions, recommendations, gripes, musings and so on.
     */
    protected function spontaneousKind(): string
    {
        $kinds = [
            'Tell a joke, pun or share something genuinely funny.',
            'Ask the community a question you actually want answers to.',
            'Share a recommendation (something you\'ve enjoyed lately) and why.',
            'Have a light-hearted rant or gripe about a small annoyance.',
            'Post a random musing or shower-thought that\'s been on your mind.',
            'Start a "what\'s your favourite…" or "unpopular opinion" style thread.',
            'Share a little story or something that happened to you recently.',
            'Ask for advice or help with something you\'re trying to figure out.',
            'Kick off a fun hypothetical or "would you rather" type question.',
            'Share a tip or something useful you\'ve learned.',
            'Post an observation or hot take about one of your hobbies.',
            'Just start some casual chit-chat to see who\'s around.',
        ];

        return $kinds[array_rand($kinds)];
    }

    /**
     * Pull the first JSON object out of a model response, tolerating stray prose or
     * code fences around it.
     */
    protected function extractJson(string $content): string
    {
        $content = trim($content);

        if (preg_match('/\{.*\}/s', $content, $m)) {
            return $m[0];
        }

        return $content;
    }

    /**
     * Generate a brand-new discussion within the context of a tag.
     *
     * @param string                                                  $tagName
     * @param string                                                  $tagDescription
     *        The tag's description (empty if none) — used to steer the topic to fit
     *        what the section is actually about.
     * @param array{title: string, summary: string, link?: string}|null $newsItem
     *        Optional current news item to inspire the discussion (the post reacts to
     *        the topic, it does not reproduce the article).
     * @param string[]                                                 $avoidTitles
     *        Recent discussion titles to steer away from, to reduce repetition.
     * @param array<string, string>|null                               $persona
     *        The authoring bot's persona, so the post is written in its own voice.
     *
     * @return array{title: string, body: string}
     */
    public function generateDiscussion(string $tagName, string $tagDescription = '', ?array $newsItem = null, array $avoidTitles = [], ?array $persona = null): array
    {
        $system = $this->settings->prompt()
            .$this->personaSystemFragment($persona)."\n\n"
            .self::MARKDOWN_GUIDANCE."\n\n"
            .self::STYLE_GUIDANCE."\n\n"
            .'Write a natural discussion to start a new thread, focused on a single clear topic '
            .'(don\'t cram in several unrelated subjects). Respond with a short title on the first '
            .'line, then a blank line, then the body. The title must be plain text (no Markdown), '
            .'and should sound like a real person wrote it — not a headline or a summary. '
            .'Markdown in the body is fine.';

        // Describe the section so the topic genuinely fits the tag's purpose.
        $section = "\"{$tagName}\"";
        if (trim($tagDescription) !== '') {
            $section .= " (this section is about: {$tagDescription})";
        }

        if (!empty($avoidTitles)) {
            $system .= "\n\nRecent threads already exist on these titles — pick a clearly different "
                ."subject and angle, do not rehash them:\n- ".implode("\n- ", $avoidTitles);
        }

        if ($newsItem !== null) {
            $summary = $newsItem['summary'] !== '' ? "\n\nSummary: {$newsItem['summary']}" : '';
            $link = !empty($newsItem['link']) ? "\n\nSource URL: {$newsItem['link']}" : '';
            $linkGuidance = !empty($newsItem['link'])
                ? ' Link to the source article once where it reads naturally (paste the URL on its '
                    .'own or inline — the forum turns it into a link); do not invent a different URL.'
                : '';

            $user = "Here's something in the news right now:\n\nHeadline: \"{$newsItem['title']}\"{$summary}{$link}\n\n"
                ."Start a thread in the {$section} section with YOUR take on this — your reaction, "
                ."opinion, or a question it raises for you, in your own voice. Don't narrate how you "
                ."found it; just get into what you actually think. Make sure it fits this section's "
                ."topic. Write in English even if the source is in another language. Don't copy or "
                .'quote the article at length.'.$linkGuidance."\n\nApproach for this post: ".$this->varietyDirective('discussion');
        } else {
            $user = "Start a new thread in the {$section} section of the forum — nothing to do with "
                ."the news, just something you actually feel like posting. "
                ."{$this->spontaneousKind()} "
                ."Pick something specific that fits this section and that someone with your interests "
                ."and personality would genuinely raise, and write it in your own voice.\n\n"
                ."Approach for this post: ".$this->varietyDirective('discussion');
        }

        $content = $this->complete($system, $user);

        // First non-empty line is the title; the remainder is the body.
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $title = trim((string) array_shift($lines));
        $body = trim(implode("\n", $lines));

        if ($title === '') {
            $title = 'Untitled discussion';
        }

        if ($body === '') {
            // Fall back to using the title as the body so we never post an empty reply.
            $body = $title;
        }

        return ['title' => $title, 'body' => $body];
    }

    /**
     * Rank which members' personas best fit a thread's topic, returning several
     * candidates (best first) rather than a single one. Returning a pool matters: the
     * single best match is deterministic per topic, so on a busy thread it is usually
     * the bot that *just* replied — which then can't reply again ("no two in a row"),
     * gridlocking the thread. A ranked pool lets the caller pick a fitting bot that
     * isn't the last poster, so a topic sustains a rotating multi-bot conversation.
     *
     * Fully dynamic — the model matches each persona's interests to the thread; no
     * hardcoded interest maps. Returns [] if it can't decide / the response is unusable.
     *
     * @param array<int, string> $personas member id => short persona blurb
     * @param int                $limit    how many ranked candidates to return
     * @return int[] member ids, best fit first (validated against $personas)
     */
    public function choosePersonasForTopic(string $discussionTitle, string $openingPost, array $personas, int $limit = 5): array
    {
        if (empty($personas)) {
            return [];
        }

        $lines = [];
        foreach ($personas as $id => $blurb) {
            $lines[] = "Member {$id}: ".trim($blurb);
        }

        $system = 'You rank which forum members are the best fit to reply to a thread, based on how '
            .'well their interests/personality match the topic. List the ones who would most '
            .'genuinely have something to say, best fit first.'."\n\n"
            .'Respond with ONLY minified JSON: {"memberIds": [<id>, <id>, ...]} — up to '.$limit
            .' ids, best first.';

        $opening = $openingPost !== '' ? "\n\nOpening post:\n".mb_substr($openingPost, 0, 600) : '';

        $user = "Thread title: \"{$discussionTitle}\".{$opening}\n\nMembers:\n".implode("\n", $lines)
            ."\n\nWhich members should reply?";

        $decoded = json_decode($this->extractJson($this->complete($system, $user)), true);

        if (!is_array($decoded) || !isset($decoded['memberIds']) || !is_array($decoded['memberIds'])) {
            return [];
        }

        // Validate each id against what we offered (guard hallucinated ids), keep order.
        $out = [];
        foreach ($decoded['memberIds'] as $id) {
            $id = (int) $id;
            if (array_key_exists($id, $personas) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Single best-fit member for a topic, or null. Thin wrapper over
     * {@see choosePersonasForTopic()} for callers that only need one.
     *
     * @param array<int, string> $personas member id => short persona blurb
     */
    public function choosePersonaForTopic(string $discussionTitle, string $openingPost, array $personas): ?int
    {
        return $this->choosePersonasForTopic($discussionTitle, $openingPost, $personas, 1)[0] ?? null;
    }

    /**
     * Cheap yes/no gate: would THIS member actually bother replying to this thread?
     * Used for autonomous (undirected) replies so bots ignore threads they'd add
     * nothing to — the way real members scroll past most discussions. Directed
     * replies (answering an @mention) skip this and always reply.
     *
     * Defaults to NOT replying on any ambiguity/parse failure, so the bias is toward
     * restraint rather than noise.
     *
     * @param array<string, string>|null $persona
     */
    public function shouldReply(string $discussionTitle, string $context, ?array $persona = null): bool
    {
        $system = 'You decide whether a specific forum member would reply to a thread. '
            .'On an active community forum, engaged members reply readily whenever they have '
            .'something to contribute — an opinion, a question, a relevant experience, agreement '
            .'with a reason, a counterpoint, or humour that fits. Lean toward replying when the '
            .'topic is within their interests or they could naturally add to the conversation.'
            ."\n\n"
            .'Crucially, judge by the SUBJECT, not whether the post agrees with the member. People '
            .'are MOST likely to chime in when someone holds the opposite view on a topic they care '
            .'about — a coffee lover will absolutely reply to "I hate coffee" (to push back or be '
            .'playful), a fan will reply to criticism of their team, and so on. A contrary, '
            .'provocative, or opinionated post is a REASON to reply, never a reason to skip. Treat a '
            .'direct question to the community ("am I the only one…?", "what do you think?") as a '
            .'strong invitation to answer.'
            ."\n\n"
            .'Only decline when replying would clearly be pointless: the topic is genuinely outside '
            .'what this member cares about at all, the conversation is fully settled, or they would '
            .'only be adding empty filler ("me too", "nice").'
            .$this->personaSystemFragment($persona)."\n\n"
            .'Answer with ONLY minified JSON: {"reply": true} if this member would reply, or '
            .'{"reply": false} if they would scroll past. When it is a reasonable call either way, '
            .'prefer true.';

        $user = "Thread title: \"{$discussionTitle}\".\n\nRecent posts:\n{$context}\n\n"
            .'Would this member reply here?';

        $decoded = json_decode($this->extractJson($this->complete($system, $user)), true);

        return is_array($decoded) && ($decoded['reply'] ?? false) === true;
    }

    /**
     * Generate a reply given the surrounding discussion context.
     *
     * @param string      $discussionTitle
     * @param string      $context      Recent posts in the thread, oldest first.
     * @param string[]    $participants Display names already in the thread that the
     *                                  reply may @-mention. Mentions are linked after
     *                                  generation, so the model just writes `@Name`.
     * @param string|null $addressedBy  Display name of someone who just mentioned/
     *                                  addressed this user; when set, the reply
     *                                  should directly answer them.
     * @param string[]    $headlines    Current news headlines the bot is "aware of",
     *                                  available as background knowledge it may draw
     *                                  on only when genuinely relevant.
     * @param array<string, string>|null $persona Authoring bot's persona (its voice).
     * @param bool $mustReply When true, the bot is not offered the [SKIP] opt-out — used
     *                        for unanswered threads we've committed to answering, so they
     *                        reliably get a reply.
     */
    public function generateReply(
        string $discussionTitle,
        string $context,
        array $participants = [],
        ?string $addressedBy = null,
        array $headlines = [],
        ?array $persona = null,
        bool $mustReply = false
    ): string {
        $mentionGuidance = '';

        if (!empty($participants)) {
            $mentionGuidance = "\n\nDo NOT @-mention other people. Just reply to the conversation "
                ."normally — refer to others by name in plain text if you must (e.g. \"I agree with "
                ."Sam\"), but do not write an @-mention. The ONLY exception: if you are directly "
                ."answering a specific question someone asked YOU, you may begin by @-mentioning that "
                ."one person — and no one else. Almost every reply should contain no @-mentions at all.";
        }

        // Give the bot current-events awareness as background knowledge. It should
        // only surface a headline if it is genuinely relevant to the thread — never
        // shoehorn news in or change the subject.
        $newsAwareness = '';
        if (!empty($headlines)) {
            $newsAwareness = "\n\nFor background awareness, here are some current news headlines (some "
                ."may be in other languages — always write your reply in English). Only reference one if "
                ."it is directly relevant to what's being discussed; otherwise ignore them entirely and "
                ."do not change the subject:\n- ".implode("\n- ", $headlines);
        }

        $directlyAddressed = $addressedBy !== null && $addressedBy !== '';

        // Let the bot bow out when it has nothing to add — real members don't reply to
        // every thread. EXCEPTIONS: when directly addressed/mentioned (a promise to the
        // person who pinged it), or when answering an unanswered thread we've committed
        // to ($mustReply) — both must produce a reply.
        $optOut = '';
        if (!$directlyAddressed && !$mustReply) {
            $optOut = "\n\nIMPORTANT — you do not have to reply. Most forum threads, most members "
                ."scroll past. Reply ONLY if you, as this specific person, genuinely have something "
                ."worth adding: a real point or counter-point, a useful answer, a relevant experience, "
                ."a question that moves it forward, or humour that actually lands. Do NOT reply just "
                ."to agree, to say \"me too\" / \"sounds good\" / \"looking forward to it\", to "
                ."restate what's been said, or to be polite. If the conversation is basically settled, "
                ."or the topic isn't something this person would care about, or you'd only be adding "
                ."filler — respond with exactly [SKIP] and nothing else. Skipping is the right, common "
                ."choice; lean toward it when in doubt.";
        }

        $system = $this->settings->prompt()
            .$this->personaSystemFragment($persona)."\n\n"
            .self::MARKDOWN_GUIDANCE
            .$mentionGuidance
            .$newsAwareness
            .$optOut."\n\n"
            .self::STYLE_GUIDANCE."\n\n"
            .'You are participating in an existing discussion. Write a single natural reply '
            .'that stays strictly on the thread\'s topic — forum replies address what is already '
            .'being discussed and do not drift onto unrelated subjects or introduce new topics. '
            .'Respond with only the reply text — no preamble, no quoting.';

        $task = 'Write your reply.';

        if ($directlyAddressed) {
            $task = "{$addressedBy} addressed you directly in the most recent post. Read what "
                ."they said and respond to them specifically — answer their question or engage "
                ."with their point directly, don't give a generic reply.";
        }

        $task .= "\n\nApproach for this reply: ".$this->varietyDirective('reply');

        $user = "Discussion title: \"{$discussionTitle}\".\n\nRecent posts:\n{$context}\n\n{$task}";

        $reply = trim($this->complete($system, $user));

        // Sentinel (or a reply that's nothing but it) → the bot chose not to post.
        if ($reply === '' || $this->isSkip($reply)) {
            return '';
        }

        return $reply;
    }

    /**
     * Whether a generated reply is the "nothing to add" opt-out sentinel. Tolerates
     * the model wrapping it in punctuation/whitespace or lowercasing it.
     */
    protected function isSkip(string $reply): bool
    {
        $normalised = strtoupper(trim($reply, " \t\n\r\0\x0B[](){}.\"'"));

        return $normalised === 'SKIP';
    }

    /**
     * Run a chat completion and return the first choice's text content.
     */
    protected function complete(string $system, string $user): string
    {
        $apiKey = $this->settings->apiKey();

        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $client = OpenAI::client($apiKey);

        $response = $client->chat()->create([
            'model' => $this->settings->model(),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        return (string) ($response->choices[0]->message->content ?? '');
    }
}

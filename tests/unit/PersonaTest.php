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

use IanM\AIChatterbox\OpenAIClient;
use IanM\AIChatterbox\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers persona generation parsing and how a persona is woven into the system
 * prompt (voice only — no topic/routing influence).
 */
class PersonaTest extends TestCase
{
    protected function clientReturning(string $response): OpenAIClient
    {
        $settings = new Settings(new ArrayRepository([
            'ianm-ai-chatterbox.prompt' => 'You are a forum member.',
        ]));

        return new class($settings, $response) extends OpenAIClient {
            public function __construct(Settings $settings, private string $canned)
            {
                parent::__construct($settings);
            }

            protected function complete(string $system, string $user): string
            {
                return $this->canned;
            }
        };
    }

    public function test_parses_a_full_persona(): void
    {
        $client = $this->clientReturning(
            '{"display_name":"coffee_nerd_82","bio":"Barista by day.","tone":"warm","interests":"espresso, latte art","quirks":"loves puns"}'
        );

        $persona = $client->generatePersona(['Coffee corner — talk coffee']);

        $this->assertSame('coffee_nerd_82', $persona['display_name']);
        $this->assertSame('Barista by day.', $persona['bio']);
        $this->assertSame('warm', $persona['tone']);
        $this->assertSame('espresso, latte art', $persona['interests']);
        $this->assertSame('loves puns', $persona['quirks']);
    }

    public function test_missing_keys_default_to_empty_strings(): void
    {
        $client = $this->clientReturning('{"display_name":"solo"}');

        $persona = $client->generatePersona();

        $this->assertSame('solo', $persona['display_name']);
        $this->assertSame('', $persona['bio']);
        $this->assertSame('', $persona['tone']);
    }

    public function test_unparseable_persona_yields_all_empty(): void
    {
        $persona = $this->clientReturning('no json here')->generatePersona();

        $this->assertSame(
            ['display_name' => '', 'bio' => '', 'tone' => '', 'interests' => '', 'quirks' => ''],
            $persona
        );
    }

    /**
     * @param array<string, string>|null $persona
     */
    protected function fragment(?array $persona): string
    {
        $client = $this->clientReturning('');
        $method = new ReflectionMethod(OpenAIClient::class, 'personaSystemFragment');

        return $method->invoke($client, $persona);
    }

    public function test_empty_persona_adds_no_fragment(): void
    {
        $this->assertSame('', $this->fragment(null));
        $this->assertSame('', $this->fragment([]));
    }

    public function test_fragment_mentions_name_and_voice(): void
    {
        $fragment = $this->fragment([
            'display_name' => 'jazz_cat',
            'bio' => 'I review records.',
            'tone' => 'laid back',
            'interests' => 'vinyl',
            'quirks' => 'lowercase only',
        ]);

        $this->assertStringContainsString('jazz_cat', $fragment);
        $this->assertStringContainsString('laid back', $fragment);
        $this->assertStringContainsString('vinyl', $fragment);
        // It must scope itself to voice, not topic/rules.
        $this->assertStringContainsString('not the forum', $fragment);
    }

    /**
     * @param 'discussion'|'reply' $kind
     */
    protected function variety(string $kind): string
    {
        $client = $this->clientReturning('');
        $method = new ReflectionMethod(OpenAIClient::class, 'varietyDirective');

        return $method->invoke($client, $kind);
    }

    public function test_variety_directive_returns_a_non_empty_angle(): void
    {
        $this->assertNotSame('', $this->variety('discussion'));
        $this->assertNotSame('', $this->variety('reply'));
    }

    public function test_variety_directive_varies_across_calls(): void
    {
        // With 10 angles, 40 draws should surface at least a few distinct ones.
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $seen[$this->variety('discussion')] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'Expected variety directives to differ across calls.');
    }

    public function test_spontaneous_kind_returns_a_non_empty_varied_value(): void
    {
        $client = $this->clientReturning('');
        $method = new ReflectionMethod(OpenAIClient::class, 'spontaneousKind');

        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $value = $method->invoke($client);
            $this->assertNotSame('', $value);
            $seen[$value] = true;
        }

        $this->assertGreaterThan(1, count($seen));
    }

    #[DataProvider('skipCases')]
    public function test_reply_skip_sentinel_yields_empty_string(string $modelOutput, bool $expectSkip): void
    {
        $body = $this->clientReturning($modelOutput)->generateReply('A thread', 'some context');

        if ($expectSkip) {
            $this->assertSame('', $body);
        } else {
            $this->assertNotSame('', $body);
        }
    }

    public function test_choose_personas_returns_ranked_pool(): void
    {
        $personas = [5 => 'football, sport', 9 => 'baking, gardening', 3 => 'politics'];

        // Best-first, validated, order preserved.
        $this->assertSame([5, 3], $this->clientReturning('{"memberIds":[5,3]}')->choosePersonasForTopic('World Cup', '', $personas));
        // Tolerates prose around the JSON.
        $this->assertSame([9], $this->clientReturning('sure: {"memberIds":[9]}')->choosePersonasForTopic('Sourdough', '', $personas));
    }

    public function test_choose_personas_drops_hallucinated_ids_and_dedupes(): void
    {
        $personas = [5 => 'football', 9 => 'baking'];

        // 999 isn't offered → dropped; duplicate 5 → deduped.
        $this->assertSame([5, 9], $this->clientReturning('{"memberIds":[5,999,5,9]}')->choosePersonasForTopic('x', '', $personas));
        $this->assertSame([], $this->clientReturning('no idea')->choosePersonasForTopic('x', '', $personas));
        $this->assertSame([], $this->clientReturning('{"memberIds":[5]}')->choosePersonasForTopic('x', '', []));
    }

    public function test_single_persona_wrapper_returns_top_or_null(): void
    {
        $personas = [5 => 'football, sport', 9 => 'baking, gardening'];

        $this->assertSame(5, $this->clientReturning('{"memberIds":[5,9]}')->choosePersonaForTopic('World Cup', '', $personas));
        $this->assertNull($this->clientReturning('{"memberIds":[]}')->choosePersonaForTopic('x', '', $personas));
        $this->assertNull($this->clientReturning('garbage')->choosePersonaForTopic('x', '', $personas));
    }

    #[DataProvider('shouldReplyCases')]
    public function test_should_reply_parses_decision_and_defaults_to_false(string $modelOutput, bool $expected): void
    {
        $decision = $this->clientReturning($modelOutput)->shouldReply('A thread', 'context');

        $this->assertSame($expected, $decision);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function shouldReplyCases(): array
    {
        return [
            'explicit yes' => ['{"reply": true}', true],
            'explicit no' => ['{"reply": false}', false],
            'yes with prose around it' => ['Sure: {"reply": true}', true],
            'unparseable defaults to no' => ['maybe?', false],
            'missing key defaults to no' => ['{"foo": 1}', false],
            'string true is not boolean true' => ['{"reply": "true"}', false],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function skipCases(): array
    {
        return [
            'bare sentinel' => ['[SKIP]', true],
            'lowercased' => ['[skip]', true],
            'no brackets' => ['SKIP', true],
            'with trailing punctuation' => ['[SKIP].', true],
            'a real reply' => ['Totally agree, the second half was the turning point.', false],
            'skip as a word inside a real reply' => ['I had to skip the first episode but caught up.', false],
        ];
    }
}

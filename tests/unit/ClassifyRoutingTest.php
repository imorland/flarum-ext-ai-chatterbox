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
use PHPUnit\Framework\TestCase;

/**
 * Covers the parsing/validation around the model's item->tag routing decision:
 * the JSON is honoured, "none" maps to null, and hallucinated ids/indexes are
 * rejected rather than trusted.
 */
class ClassifyRoutingTest extends TestCase
{
    /**
     * An OpenAIClient whose model response is canned, so we exercise only the
     * parse + validate logic in classifyItemToTag() (no network, no API key).
     */
    protected function clientReturning(string $response): OpenAIClient
    {
        return new class($response) extends OpenAIClient {
            public function __construct(private string $canned)
            {
                // Skip the parent constructor (needs Settings).
            }

            protected function complete(string $system, string $user): string
            {
                return $this->canned;
            }
        };
    }

    /**
     * @return array<int, array{title: string, summary: string, link: string}>
     */
    protected function items(): array
    {
        return [
            ['title' => 'A', 'summary' => '', 'link' => ''],
            ['title' => 'B', 'summary' => '', 'link' => ''],
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, description: string}>
     */
    protected function tags(): array
    {
        return [
            ['id' => 10, 'name' => 'World Cup', 'description' => 'football'],
            ['id' => 20, 'name' => 'Coffee', 'description' => 'coffee'],
        ];
    }

    public function test_parses_a_valid_pairing(): void
    {
        $client = $this->clientReturning('{"tagId": 20, "itemIndex": 1}');

        $this->assertSame(
            ['tagId' => 20, 'itemIndex' => 1],
            $client->classifyItemToTag($this->items(), $this->tags())
        );
    }

    public function test_tolerates_prose_and_code_fences_around_json(): void
    {
        $client = $this->clientReturning("Sure!\n```json\n{\"tagId\": 10, \"itemIndex\": 0}\n```");

        $this->assertSame(
            ['tagId' => 10, 'itemIndex' => 0],
            $client->classifyItemToTag($this->items(), $this->tags())
        );
    }

    public function test_none_response_maps_to_null(): void
    {
        $client = $this->clientReturning('{"tagId": null, "itemIndex": null}');

        $this->assertNull($client->classifyItemToTag($this->items(), $this->tags()));
    }

    public function test_rejects_hallucinated_tag_id(): void
    {
        $client = $this->clientReturning('{"tagId": 999, "itemIndex": 0}');

        $this->assertNull($client->classifyItemToTag($this->items(), $this->tags()));
    }

    public function test_rejects_out_of_range_item_index(): void
    {
        $client = $this->clientReturning('{"tagId": 10, "itemIndex": 7}');

        $this->assertNull($client->classifyItemToTag($this->items(), $this->tags()));
    }

    public function test_rejects_unparseable_response(): void
    {
        $client = $this->clientReturning('I could not decide, sorry.');

        $this->assertNull($client->classifyItemToTag($this->items(), $this->tags()));
    }

    public function test_empty_inputs_short_circuit_to_null(): void
    {
        $client = $this->clientReturning('{"tagId": 10, "itemIndex": 0}');

        $this->assertNull($client->classifyItemToTag([], $this->tags()));
        $this->assertNull($client->classifyItemToTag($this->items(), []));
    }
}

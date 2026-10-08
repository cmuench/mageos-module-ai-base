<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Chat;

use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;
use PHPUnit\Framework\TestCase;

/**
 * The JSON a chunk encodes to is what a frontend reads off an SSE stream, so these assert on the
 * encoded string rather than on the array behind it: `[]` versus `{}` only shows up there.
 */
final class StreamChunkTest extends TestCase
{
    public function test_it_encodes_a_text_chunk_as_its_type_and_text(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Text, 'Hello');

        self::assertSame('{"type":"text","text":"Hello"}', json_encode($chunk));
    }

    public function test_it_encodes_a_thinking_chunk_as_its_type_and_text(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Thinking, 'weighing options');

        self::assertSame('{"type":"thinking","text":"weighing options"}', json_encode($chunk));
    }

    /**
     * ThinkingStart signals that a reasoning block opened before the model wrote anything into
     * it, so there is nothing to carry beyond the type.
     */
    public function test_it_encodes_a_thinking_start_chunk_as_only_its_type(): void
    {
        $chunk = new StreamChunk(StreamChunkType::ThinkingStart);

        self::assertSame('{"type":"thinking_start"}', json_encode($chunk));
    }

    public function test_it_encodes_a_tool_call_chunk_with_the_call_nested_under_tool_call(): void
    {
        $chunk = new StreamChunk(
            StreamChunkType::ToolCall,
            '',
            new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']),
        );

        self::assertSame(
            '{"type":"tool_call","tool_call":{"id":"toolu_01","name":"get_orders","arguments":{"status":"pending"}}}',
            json_encode($chunk),
        );
    }

    /**
     * A ToolCallStart chunk carries the same shape as a completed ToolCall chunk, arguments
     * empty, so a consumer does not need a second case for it. Empty arguments must still be an
     * object, or `arguments.status` on the frontend reads off an array.
     */
    public function test_it_encodes_a_tool_call_start_chunk_with_empty_arguments_as_an_object(): void
    {
        $chunk = new StreamChunk(
            StreamChunkType::ToolCallStart,
            '',
            new ToolCall('toolu_01', 'get_orders', []),
        );

        self::assertSame(
            '{"type":"tool_call_start","tool_call":{"id":"toolu_01","name":"get_orders","arguments":{}}}',
            json_encode($chunk),
        );
    }

    public function test_it_encodes_a_usage_chunk_with_every_token_count(): void
    {
        $chunk = new StreamChunk(
            StreamChunkType::Usage,
            '',
            null,
            new TokenUsage(120, 45, 165, 100, 12, 30),
        );

        self::assertSame(
            '{"type":"usage","usage":{"prompt_tokens":120,"completion_tokens":45,"total_tokens":165,'
            . '"cache_read_tokens":100,"cache_write_tokens":30,"reasoning_tokens":12}}',
            json_encode($chunk),
        );
    }

    public function test_it_encodes_counts_the_provider_did_not_report_as_null(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(120, 45, 165));

        self::assertSame(
            '{"type":"usage","usage":{"prompt_tokens":120,"completion_tokens":45,"total_tokens":165,'
            . '"cache_read_tokens":null,"cache_write_tokens":null,"reasoning_tokens":null}}',
            json_encode($chunk),
        );
    }
}

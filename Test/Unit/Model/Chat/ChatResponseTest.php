<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Chat;

use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\Reasoning;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;
use PHPUnit\Framework\TestCase;

final class ChatResponseTest extends TestCase
{
    public function test_exposes_text_tool_calls_usage_and_finish_reason(): void
    {
        $call = new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']);
        $response = new ChatResponse(
            'Let me look',
            [$call],
            new TokenUsage(120, 45),
            FinishReason::ToolCall,
            'tool_use',
        );

        self::assertSame('Let me look', $response->getText());
        self::assertSame([$call], $response->getToolCalls());
        self::assertSame(120, $response->getUsage()?->getPromptTokens());
        self::assertSame(FinishReason::ToolCall, $response->getFinishReason());
        self::assertSame('tool_use', $response->getRawFinishReason());
    }

    /**
     * The tool loop's exit condition. Reading it off getToolCalls() at every call site is how a
     * loop ends up continuing on an empty array.
     */
    public function test_reports_whether_the_model_asked_for_a_tool(): void
    {
        $withCall = new ChatResponse('', [new ToolCall('toolu_01', 'get_orders', [])]);

        self::assertTrue($withCall->hasToolCalls());
        self::assertFalse((new ChatResponse('Done'))->hasToolCalls());
    }

    public function test_usage_is_absent_when_the_provider_reported_none(): void
    {
        self::assertNull((new ChatResponse('Done'))->getUsage());
    }

    /**
     * The signature is opaque and provider-specific, so it has to survive on the value object
     * exactly as the provider issued it, ready to be carried back through withAssistantTurn().
     */
    public function test_exposes_the_reasoning_blocks_the_model_produced(): void
    {
        $reasoning = new Reasoning('weighing options', 'sig_abc');
        $response = new ChatResponse('Let me look', [], null, null, null, [$reasoning]);

        self::assertSame([$reasoning], $response->getReasoning());
        self::assertSame('weighing options', $response->getReasoning()[0]->getText());
        self::assertSame('sig_abc', $response->getReasoning()[0]->getSignature());
    }

    public function test_reports_no_reasoning_when_none_was_given(): void
    {
        self::assertSame([], (new ChatResponse('Done'))->getReasoning());
    }

    public function test_a_reasoning_block_may_carry_no_signature(): void
    {
        self::assertNull((new Reasoning('weighing options'))->getSignature());
    }

    /**
     * Providers report prompt and completion separately and only sometimes report a total, so a
     * consumer billing on totals cannot rely on the provider having sent one.
     */
    public function test_token_usage_totals_prompt_and_completion_when_no_total_was_reported(): void
    {
        self::assertSame(165, (new TokenUsage(120, 45))->getTotalTokens());
        self::assertSame(200, (new TokenUsage(120, 45, 200))->getTotalTokens());
    }

    public function test_token_usage_reports_no_total_when_nothing_is_known(): void
    {
        self::assertNull((new TokenUsage())->getTotalTokens());
    }

    public function test_token_usage_totals_a_single_known_side(): void
    {
        self::assertSame(120, (new TokenUsage(120))->getTotalTokens());
        self::assertSame(45, (new TokenUsage(null, 45))->getTotalTokens());
    }

    public function test_a_text_chunk_carries_its_delta(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Text, 'Hel');

        self::assertSame(StreamChunkType::Text, $chunk->getType());
        self::assertSame('Hel', $chunk->getText());
        self::assertNull($chunk->getToolCall());
    }

    public function test_a_tool_call_chunk_carries_the_completed_call(): void
    {
        $call = new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']);

        $chunk = new StreamChunk(StreamChunkType::ToolCall, '', $call);

        self::assertSame(StreamChunkType::ToolCall, $chunk->getType());
        self::assertSame($call, $chunk->getToolCall());
        self::assertSame('', $chunk->getText());
    }

    public function test_a_usage_chunk_carries_the_counts(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(120, 45));

        self::assertSame(StreamChunkType::Usage, $chunk->getType());
        self::assertSame(45, $chunk->getUsage()?->getCompletionTokens());
    }

    /**
     * A tool call serializes the same way on a buffered response as inside a stream chunk, so a
     * frontend handles both with one code path.
     */
    public function test_a_tool_call_encodes_the_same_on_a_response_as_in_a_stream_chunk(): void
    {
        $toolCall = new ToolCall('toolu_01', 'get_orders', ['status' => 'pending']);
        $chunk = new StreamChunk(StreamChunkType::ToolCall, '', $toolCall);

        self::assertSame(
            json_encode($toolCall),
            json_encode(json_decode((string) json_encode($chunk))->tool_call),
        );
    }

    public function test_a_thinking_chunk_carries_its_delta(): void
    {
        $chunk = new StreamChunk(StreamChunkType::Thinking, 'weighing options');

        self::assertSame(StreamChunkType::Thinking, $chunk->getType());
        self::assertSame('weighing options', $chunk->getText());
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Client;

use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Model\Chat\ChatMessage;
use MageOS\AiBase\Model\Chat\ChatRequest;
use MageOS\AiBase\Model\Client\AiExceptionMapper;
use MageOS\AiBase\Model\Client\BridgeRegistry;
use MageOS\AiBase\Model\Client\OptionNormalizer;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use MageOS\AiBase\Model\Client\UsageNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A stream Anthropic cut off at the output token limit, driven through the real Anthropic bridge.
 *
 * The truncation surfaces as a `MaxOutputTokensException` thrown by the bridge's own event
 * converter at `message_stop`, after every thinking and text event has already been converted, so
 * a fake platform would only prove what the fake does. Issue #63: the returned turn lost the
 * signed reasoning block, and a tool loop replaying that turn would have dropped it.
 */
final class SymfonyAiClientTruncatedStreamTest extends TestCase
{
    /**
     * Anthropic's SSE for a turn that thought, started answering, and hit max_tokens.
     */
    private const TRUNCATED_STREAM = <<<'SSE'
event: message_start
data: {"type":"message_start","message":{"id":"msg_1","type":"message","role":"assistant","model":"claude-3-7-sonnet-20250219","content":[],"stop_reason":null,"usage":{"input_tokens":10,"output_tokens":1}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"Weighing the options"}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"signature_delta","signature":"sig-abc"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: content_block_start
data: {"type":"content_block_start","index":1,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":1,"delta":{"type":"text_delta","text":"Partial"}}

event: content_block_stop
data: {"type":"content_block_stop","index":1}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"max_tokens","stop_sequence":null},"usage":{"output_tokens":20}}

event: message_stop
data: {"type":"message_stop"}


SSE;

    public function test_a_truncated_stream_keeps_the_signed_reasoning_it_completed(): void
    {
        $stream = $this->client()->streamChat($this->request());
        iterator_to_array($stream, false);

        $turn = $stream->getReturn();

        self::assertSame(FinishReason::Length, $turn->getFinishReason());
        self::assertSame('Partial', $turn->getText());
        self::assertCount(1, $turn->getReasoning());
        self::assertSame('Weighing the options', $turn->getReasoning()[0]->getText());
        self::assertSame('sig-abc', $turn->getReasoning()[0]->getSignature());
    }

    /**
     * The point of keeping it: the turn goes back to the provider on the next request, signature
     * and all, the way a turn that finished normally does.
     */
    public function test_the_truncated_turn_replays_its_reasoning_on_the_next_request(): void
    {
        $stream = $this->client()->streamChat($this->request());
        iterator_to_array($stream, false);

        $next = $this->request()->withAssistantTurn($stream->getReturn());

        $replayed = $next->getMessages()[1];
        self::assertSame(MessageRole::Assistant, $replayed->getRole());
        self::assertCount(1, $replayed->getReasoning());
        self::assertSame('sig-abc', $replayed->getReasoning()[0]->getSignature());
    }

    private function client(): SymfonyAiClient
    {
        $httpClient = new MockHttpClient(
            static fn (): MockResponse => new MockResponse(
                self::TRUNCATED_STREAM,
                ['response_headers' => ['content-type: text/event-stream']]
            )
        );

        return new SymfonyAiClient(
            Factory::createPlatform('test-key', $httpClient),
            'claude-3-7-sonnet-20250219',
            'anthropic',
            '_row_1',
            new OptionNormalizer(new BridgeRegistry([]), []),
            new UsageNormalizer(new BridgeRegistry(['anthropic' => ['cache_outside_prompt' => true]])),
            new AiExceptionMapper(),
            new BridgeRegistry([]),
            new NullLogger(),
        );
    }

    private function request(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(MessageRole::User, 'Compare the two products')]);
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\TokenUsageInterface;
use MageOS\AiBase\Api\Data\ToolCallInterface;

class StreamChunk implements StreamChunkInterface
{
    /**
     * @param StreamChunkType $type
     * @param string $text
     * @param ToolCallInterface|null $toolCall
     * @param TokenUsageInterface|null $usage
     */
    public function __construct(
        private readonly StreamChunkType $type,
        private readonly string $text = '',
        private readonly ?ToolCallInterface $toolCall = null,
        private readonly ?TokenUsageInterface $usage = null,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getType(): StreamChunkType
    {
        return $this->type;
    }

    /**
     * @inheritdoc
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @inheritdoc
     */
    public function getToolCall(): ?ToolCallInterface
    {
        return $this->toolCall;
    }

    /**
     * @inheritdoc
     */
    public function getUsage(): ?TokenUsageInterface
    {
        return $this->usage;
    }

    /**
     * The chunk as JSON: its type, plus the one payload that type carries.
     *
     * The tool call and usage serialize themselves, so their shape is defined once and is the same
     * whether it arrives in a stream or on a buffered response.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type->value] + match ($this->type) {
            StreamChunkType::Text, StreamChunkType::Thinking => ['text' => $this->text],
            StreamChunkType::ThinkingStart => [],
            StreamChunkType::ToolCall, StreamChunkType::ToolCallStart => ['tool_call' => $this->toolCall],
            StreamChunkType::Usage => ['usage' => $this->usage],
        };
    }
}

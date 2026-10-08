<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * One event from a streamed chat.
 *
 * Serializes to `{"type": <StreamChunkType value>, ...}` so a chunk can go straight to the browser
 * with `json_encode($chunk)`. Text and thinking chunks add `"text"`, tool call chunks add
 * `"tool_call"` (see {@see ToolCallInterface}), usage chunks add `"usage"` (see
 * {@see TokenUsageInterface}), and a thinking start chunk adds nothing. The JSON keys are part of
 * this interface's contract.
 *
 * Stable to call, not meant to be implemented: methods may be added in a minor release. To change
 * what it does, write a plugin on it instead of replacing it.
 *
 * @api
 */
interface StreamChunkInterface extends \JsonSerializable
{
    /**
     * What kind of event this is.
     *
     * @return StreamChunkType
     */
    public function getType(): StreamChunkType;

    /**
     * Text delta for text and thinking chunks; empty for every other type.
     *
     * @return string
     */
    public function getText(): string;

    /**
     * The tool call, for tool call chunks only.
     *
     * Arguments are empty on a {@see StreamChunkType::ToolCallStart} chunk, since the model has
     * only just opened the call; the completed arguments arrive later on a
     * {@see StreamChunkType::ToolCall} chunk.
     *
     * @return ToolCallInterface|null
     */
    public function getToolCall(): ?ToolCallInterface;

    /**
     * Token counts, for usage chunks only.
     *
     * @return TokenUsageInterface|null
     */
    public function getUsage(): ?TokenUsageInterface;
}

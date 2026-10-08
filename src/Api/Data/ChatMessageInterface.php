<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * One message in a conversation.
 *
 * Stable to call, not meant to be implemented: methods may be added in a minor release. To change
 * what it does, write a plugin on it instead of replacing it.
 *
 * @api
 */
interface ChatMessageInterface
{
    /**
     * Who authored this message.
     *
     * @return MessageRole
     */
    public function getRole(): MessageRole;

    /**
     * Text of the message. Empty for an assistant turn that only requested tools.
     *
     * @return string
     */
    public function getContent(): string;

    /**
     * Tool calls this assistant message requested; empty for every other role.
     *
     * @return list<ToolCallInterface>
     */
    public function getToolCalls(): array;

    /**
     * Reasoning blocks this assistant turn carried, oldest first; empty for every other role.
     *
     * Carried opaque, exactly as {@see ChatResponseInterface::getReasoning()} returned it, so a
     * tool loop that stores this message and replays it later sends the provider back what it
     * originally issued.
     *
     * @return list<ReasoningInterface>
     */
    public function getReasoning(): array;

    /**
     * The tool call this message answers, for tool-role messages only.
     *
     * The whole call rather than its id: providers want the invocation echoed back alongside its
     * result, so a message that kept only the id could not be rendered into a request.
     *
     * @return ToolCallInterface|null
     */
    public function getAnsweredToolCall(): ?ToolCallInterface;

    /**
     * Id of the tool call this message answers, for tool-role messages only.
     *
     * @return string|null
     */
    public function getToolCallId(): ?string;
}

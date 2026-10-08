<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * One reasoning block the model produced before its answer or tool calls.
 *
 * Providers expect this echoed back unchanged on the next request of a tool loop: Anthropic
 * verifies the signature against the thinking block it belongs to, OpenAI and Azure return an
 * encrypted `reasoning` item that must be replayed as-is, and Gemini attaches a `thoughtSignature`
 * it checks the same way. The signature is opaque to this module: it is never inspected, edited or
 * rendered, only carried. A consumer that stores the transcript stores it as received.
 *
 * Stable to call, not meant to be implemented: methods may be added in a minor release. To change
 * what it does, write a plugin on it instead of replacing it.
 *
 * @api
 */
interface ReasoningInterface
{
    /**
     * The reasoning text the model wrote, or the provider's summary of it.
     *
     * @return string
     */
    public function getText(): string;

    /**
     * Opaque provider signature this block must be replayed with.
     *
     * Null when the provider issued none for it.
     *
     * @return string|null
     */
    public function getSignature(): ?string;
}

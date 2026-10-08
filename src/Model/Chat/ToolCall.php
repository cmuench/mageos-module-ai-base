<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ToolCallInterface;

class ToolCall implements ToolCallInterface
{
    /**
     * @param string $id
     * @param string $name
     * @param array<string,mixed> $arguments Decoded from the provider JSON
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly array $arguments = [],
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * The call as JSON, with its arguments always encoded as an object.
     *
     * A call without arguments holds an empty PHP array, which json_encode would write as `[]`;
     * a consumer reading `arguments.someKey` expects an object either way.
     *
     * @return array{id: string, name: string, arguments: object}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => (object) $this->arguments,
        ];
    }
}

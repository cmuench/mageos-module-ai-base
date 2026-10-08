<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;

/**
 * A registered provider definition with whatever code and fields a test needs, and no models.
 *
 * Lets a test register a provider under the code of one whose module was removed, which is how
 * reinstalling that module looks from inside this one.
 */
final class FakeAiServiceConfiguration implements AiServiceConfigurationInterface
{
    /**
     * @param string $code
     * @param list<FieldDescriptorInterface> $fields
     */
    public function __construct(
        private readonly string $code,
        private readonly array $fields = [],
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return ucfirst($this->code);
    }

    public function getConfigurationFields(): array
    {
        return $this->fields;
    }

    public function getSupportedModels(): array
    {
        return [];
    }
}

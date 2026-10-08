<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Providers implement this by extending {@see \MageOS\AiBase\AiServices\AbstractAiService}, which
 * ships a default for every method added in a minor release. A provider implementing this
 * interface directly has to add those methods itself.
 *
 * @api
 */
interface AiServiceConfigurationInterface
{
    /**
     * Machine code identifying this AI backend.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Human-readable display name shown in the admin form.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Field descriptors rendered in the admin configuration form.
     *
     * @return FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array;

    /**
     * Curated model list for this backend.
     *
     * @return array<string, string> value => label; empty array for services with no model list
     */
    public function getSupportedModels(): array;
}

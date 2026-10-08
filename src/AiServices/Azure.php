<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;

class Azure extends AbstractAiService
{
    /**
     * API version sent when the row does not name one.
     */
    private const DEFAULT_API_VERSION = '2024-10-21';

    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'azure';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Azure OpenAI';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'gpt-4o'      => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o mini',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'o1'          => 'o1',
            'o1-mini'     => 'o1 mini',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField(),
            $this->fieldFactory->create([
                'name'    => 'endpoint',
                'label'   => 'Endpoint',
                'type'    => FieldDescriptorInterface::TYPE_TEXT,
                'default' => 'https://<resource>.openai.azure.com',
            ]),
            $this->modelField($this->getSupportedModels()),
        ];
    }

    /**
     * Endpoint, deployment, API version and key, the order Azure's bridge factory takes them in.
     *
     * Azure routes by deployment name rather than by model, so a row without a separate deployment
     * uses its model name as the deployment, which is how most deployments are named.
     *
     * @param array<string,mixed> $configuration
     * @return list<mixed>
     */
    public function getPlatformArguments(array $configuration): array
    {
        $deployment = $this->stringValue($configuration, 'deployment');

        return [
            $this->stringValue($configuration, 'endpoint'),
            $deployment !== '' ? $deployment : $this->stringValue($configuration, self::FIELD_MODEL),
            $this->stringValue($configuration, 'api_version', self::DEFAULT_API_VERSION),
            $this->stringValue($configuration, self::FIELD_API_KEY),
        ];
    }
}

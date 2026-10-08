<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

class Deepseek extends AbstractAiService
{
    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'deepseek';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'DeepSeek';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [
            'deepseek-chat'     => 'DeepSeek V3',
            'deepseek-reasoner' => 'DeepSeek R1',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField(),
            $this->modelField($this->getSupportedModels()),
        ];
    }
}

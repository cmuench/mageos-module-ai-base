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
     * DeepSeek's two API aliases, labelled without a version: each alias moves to the newest model.
     *
     * @return array<string, string>
     */
    public function getSupportedModels(): array
    {
        return [
            'deepseek-chat'     => 'DeepSeek Chat',
            'deepseek-reasoner' => 'DeepSeek Reasoner',
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

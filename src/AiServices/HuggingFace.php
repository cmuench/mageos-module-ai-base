<?php

declare(strict_types=1);

namespace MageOS\AiBase\AiServices;

class HuggingFace extends AbstractAiService
{
    /**
     * @inheritdoc
     */
    public function getCode(): string
    {
        return 'huggingface';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Hugging Face';
    }

    /**
     * @inheritdoc
     */
    public function getSupportedModels(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getConfigurationFields(): array
    {
        return [
            $this->apiKeyField(),
            $this->freeTextModelField(),
        ];
    }
}

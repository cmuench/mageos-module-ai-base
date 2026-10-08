<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ModelList;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\Config\ConfigScope;

/**
 * Single merge point between refreshed (stored) model lists and each service's curated defaults.
 *
 * Service classes stay pure — their getSupportedModels() never reads storage; consumers that want
 * the effective list (the admin form) go through this resolver instead.
 */
class Resolver
{
    /**
     * @param Storage $storage
     */
    public function __construct(
        private readonly Storage $storage,
    ) {
    }

    /**
     * Effective model list for a provider, before any row of it has been refreshed.
     *
     * Returns the list an older version stored per provider code when present and non-empty,
     * otherwise the curated defaults. This is what a newly added row starts out with.
     *
     * @param AiServiceConfigurationInterface $service
     * @return array<string, string> Map of model value => label
     */
    public function getModels(AiServiceConfigurationInterface $service): array
    {
        $legacy = $this->storage->getLegacyModels($service->getCode());
        if ($legacy !== null && $legacy !== []) {
            return $legacy;
        }

        return $service->getSupportedModels();
    }

    /**
     * Effective model list for one configured row, as seen from the scope being edited.
     *
     * The row's own refreshed list wins, because it came from the endpoint that row points at; a
     * provider-wide list is only a guess for a self-hosted one. Without one it falls back to
     * {@see getModels()}.
     *
     * @param AiServiceConfigurationInterface $service
     * @param string $rowId
     * @param ConfigScope $scope
     * @return array<string, string> Map of model value => label
     */
    public function getModelsForRow(AiServiceConfigurationInterface $service, string $rowId, ConfigScope $scope): array
    {
        $stored = $this->storage->getModelsForRow($rowId, $scope);
        if ($stored !== null && $stored !== []) {
            return $stored;
        }

        return $this->getModels($service);
    }
}

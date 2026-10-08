<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

/**
 * One stored copy of the services configuration, exactly as it sits in `core_config_data`.
 *
 * The services value can be stored at default, website and store scope, each its own row with its
 * own ciphertexts. Re-encryption has to treat every copy separately and write each back only over
 * the value it read, which is why the row id and the raw value travel together.
 */
class StoredServicesValue
{
    /**
     * @param int $configId Primary key of the `core_config_data` row
     * @param string $scope `default`, `websites` or `stores`
     * @param int $scopeId
     * @param string $value Raw stored JSON, credentials still encrypted
     */
    public function __construct(
        public readonly int $configId,
        public readonly string $scope,
        public readonly int $scopeId,
        public readonly string $value,
    ) {
    }
}

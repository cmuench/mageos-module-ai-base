<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\ResourceConnection;
use MageOS\AiBase\Model\AiServiceSelector;

/**
 * {@see StoredServicesStorageInterface} over `core_config_data` on the default connection.
 */
class StoredServicesStorage implements StoredServicesStorageInterface
{
    /**
     * Table every scope's copy of the services value is stored in.
     */
    private const TABLE = 'core_config_data';

    /**
     * @param ResourceConnection $resourceConnection
     * @param ConfigCache $configCache The config cache itself rather than TypeListInterface: cleaning
     *        through the type list makes Magento_Config warm the cache again on the spot, reading
     *        every scope back, which can fail on an unrelated stale scope after the rewrite already
     *        succeeded and report the whole re-encryption as failed. The next read warms it instead.
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ConfigCache $configCache,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getAll(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['config_id', 'scope', 'scope_id', 'value'])
            ->where('path = ?', AiServiceSelector::CONFIG_PATH_AI_SERVICES)
            ->where('value IS NOT NULL')
            ->where('value != ?', '');

        return array_values(array_filter(array_map(
            fn (mixed $row): ?StoredServicesValue => $this->toStoredValue($row),
            $connection->fetchAll($select),
        )));
    }

    /**
     * A fetched row as a value object, or null for one that is not shaped like a config row.
     *
     * @param mixed $row
     * @return StoredServicesValue|null
     */
    private function toStoredValue(mixed $row): ?StoredServicesValue
    {
        if (!is_array($row) || !is_string($row['value'] ?? null) || !is_numeric($row['config_id'] ?? null)) {
            return null;
        }

        return new StoredServicesValue(
            (int) $row['config_id'],
            is_string($row['scope'] ?? null) ? $row['scope'] : '',
            is_numeric($row['scope_id'] ?? null) ? (int) $row['scope_id'] : 0,
            $row['value'],
        );
    }

    /**
     * @inheritdoc
     */
    public function replace(StoredServicesValue $stored, string $replacement): bool
    {
        return $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['value' => $replacement],
            ['config_id = ?' => $stored->configId, 'value = ?' => $stored->value],
        ) === 1;
    }

    /**
     * @inheritdoc
     */
    public function invalidateCache(): void
    {
        $this->configCache->clean();
    }
}

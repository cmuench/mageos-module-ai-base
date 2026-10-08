<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use MageOS\AiBase\Model\Config\StoredServicesStorageInterface;
use MageOS\AiBase\Model\Config\StoredServicesValue;

/**
 * A {@see StoredServicesStorageInterface} over an in-memory `core_config_data`, so a test can seed
 * stored values per scope, simulate a concurrent admin save with {@see givenChangedOnWrite()}, and
 * read back what ended up stored.
 */
final class InMemoryStoredServicesStorage implements StoredServicesStorageInterface
{
    /**
     * @var array<int,StoredServicesValue>
     */
    private array $values = [];

    /**
     * Config ids whose value an "admin" replaces just before this storage writes.
     *
     * @var array<int,string>
     */
    private array $concurrentWrites = [];

    private int $cacheInvalidations = 0;

    public function withValue(int $configId, string $scope, int $scopeId, string $value): self
    {
        $this->values[$configId] = new StoredServicesValue($configId, $scope, $scopeId, $value);

        return $this;
    }

    public function givenChangedOnWrite(int $configId, string $concurrentValue): self
    {
        $this->concurrentWrites[$configId] = $concurrentValue;

        return $this;
    }

    public function getAll(): array
    {
        return array_values($this->values);
    }

    public function replace(StoredServicesValue $stored, string $replacement): bool
    {
        if (isset($this->concurrentWrites[$stored->configId])) {
            $this->withValue($stored->configId, $stored->scope, $stored->scopeId, $this->concurrentWrites[$stored->configId]);
        }

        $current = $this->values[$stored->configId] ?? null;
        if ($current === null || $current->value !== $stored->value) {
            return false;
        }

        $this->withValue($stored->configId, $stored->scope, $stored->scopeId, $replacement);

        return true;
    }

    public function invalidateCache(): void
    {
        $this->cacheInvalidations++;
    }

    public function getValue(int $configId): ?string
    {
        return ($this->values[$configId] ?? null)?->value;
    }

    public function getCacheInvalidations(): int
    {
        return $this->cacheInvalidations;
    }
}

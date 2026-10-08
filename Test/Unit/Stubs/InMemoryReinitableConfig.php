<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\App\Config\ReinitableConfigInterface;

/**
 * A {@see ReinitableConfigInterface} holding one value per path, the same at every scope (an
 * install without website or store overrides), that counts how often it was reinitialised.
 */
final class InMemoryReinitableConfig implements ReinitableConfigInterface
{
    /**
     * @var array<string,mixed>
     */
    private array $values = [];

    private int $reinitCount = 0;

    public function withValue(string $path, mixed $value): self
    {
        $this->values[$path] = $value;

        return $this;
    }

    public function getValue($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return (bool) ($this->values[$path] ?? false);
    }

    public function setValue($path, $value, $scopeType = self::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        $this->values[$path] = $value;
    }

    public function reinit()
    {
        $this->reinitCount++;

        return $this;
    }

    public function getReinitCount(): int
    {
        return $this->reinitCount;
    }
}

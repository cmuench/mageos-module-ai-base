<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * A {@see LockManagerInterface} that keeps its locks in memory, so a test can hold a lock "from
 * another process" with {@see givenLockHeldElsewhere()} and read back what is still locked
 * afterwards, instead of asserting on mocked calls.
 */
final class InMemoryLockManager implements LockManagerInterface
{
    /**
     * Names currently locked, by this subject or by the simulated other process.
     *
     * @var array<string,true>
     */
    private array $lockedNames = [];

    /**
     * Every name a lock was asked for, in order, whether or not it was granted.
     *
     * @var list<string>
     */
    private array $requestedNames = [];

    /**
     * Simulates another process already holding $name, so the next lock() on it is refused.
     *
     * @param string $name
     * @return void
     */
    public function givenLockHeldElsewhere(string $name): void
    {
        $this->lockedNames[$name] = true;
    }

    /**
     * @inheritdoc
     */
    public function lock(string $name, int $timeout = -1): bool
    {
        $this->requestedNames[] = $name;
        if (isset($this->lockedNames[$name])) {
            return false;
        }

        $this->lockedNames[$name] = true;

        return true;
    }

    /**
     * @inheritdoc
     */
    public function unlock(string $name): bool
    {
        if (!isset($this->lockedNames[$name])) {
            return false;
        }

        unset($this->lockedNames[$name]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function isLocked(string $name): bool
    {
        return isset($this->lockedNames[$name]);
    }

    /**
     * @return list<string>
     */
    public function getRequestedNames(): array
    {
        return $this->requestedNames;
    }
}

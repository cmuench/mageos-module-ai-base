<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use MageOS\AiBase\Model\Usage\UsageTransactionInterface;

/**
 * In-memory stand-in for {@see UsageTransactionInterface} that runs the unit straight through and
 * records what happened inside it.
 *
 * A fake cannot roll a real database back, and does not try to. What it can pin is the structural
 * property the production code depends on: that a day's aggregate write and the delete behind it
 * happen inside one unit, so that the database's own rollback covers both. Whether MySQL then
 * honours the transaction is MySQL's contract, not this module's.
 */
final class FakeUsageTransaction implements UsageTransactionInterface
{
    /**
     * @var array<int,string[]>
     */
    private array $units = [];

    private ?int $currentUnit = null;

    public function run(callable $work): int
    {
        $this->units[] = [];
        $this->currentUnit = array_key_last($this->units);

        try {
            return $work();
        } finally {
            $this->currentUnit = null;
        }
    }

    /**
     * Called by the other fakes to note that they were used, so a test can see which calls shared
     * a unit and which happened outside one.
     *
     * @param string $call
     * @return void
     */
    public function record(string $call): void
    {
        if ($this->currentUnit === null) {
            return;
        }

        $this->units[$this->currentUnit][] = $call;
    }

    /**
     * @return array<int,string[]>
     */
    public function getUnits(): array
    {
        return $this->units;
    }
}

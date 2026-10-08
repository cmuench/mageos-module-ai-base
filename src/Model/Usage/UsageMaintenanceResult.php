<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

/**
 * Outcome of one {@see UsageMaintenance::run()} pass.
 *
 * A plain value object rather than a void return: the cron and the CLI command both need to
 * report what happened (`bin/magento` output, a cron log line), and neither should have to reach
 * back into the two repositories to work it out after the fact.
 */
class UsageMaintenanceResult
{
    /**
     * @param int $aggregatedRows Raw `mageos_ai_usage_log` rows folded into a daily aggregate
     *        this run.
     * @param int $deletedRows Raw `mageos_ai_usage_log` rows deleted this run. Always equal to
     *        $aggregatedRows: nothing is ever deleted that was not first rolled up.
     * @param int $prunedDailyRows `mageos_ai_usage_daily` rows deleted this run for being past
     *        the daily retention window.
     * @param bool $isSkipped True when the run did nothing because another run held the roll-up
     *        lock. Carried separately from the counts because "skipped" and "ran, found nothing to
     *        do" are both all zeros, and a caller logging a summary has to tell them apart.
     */
    public function __construct(
        private readonly int $aggregatedRows,
        private readonly int $deletedRows,
        private readonly int $prunedDailyRows,
        private readonly bool $isSkipped = false,
    ) {
    }

    /**
     * Whether this run was skipped because another run held the roll-up lock.
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->isSkipped;
    }

    /**
     * Raw rows folded into a daily aggregate this run.
     *
     * @return int
     */
    public function getAggregatedRows(): int
    {
        return $this->aggregatedRows;
    }

    /**
     * Raw rows deleted this run.
     *
     * @return int
     */
    public function getDeletedRows(): int
    {
        return $this->deletedRows;
    }

    /**
     * Daily rows pruned this run for being past the daily retention window.
     *
     * @return int
     */
    public function getPrunedDailyRows(): int
    {
        return $this->prunedDailyRows;
    }
}

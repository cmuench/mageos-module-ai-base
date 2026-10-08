<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Housekeeping for the usage-tracking tables: rolls whole local days of
 * `mageos_ai_usage_log` older than the configured retention window up into
 * `mageos_ai_usage_daily`, deletes exactly the raw rows it rolled up, and then prunes daily rows
 * past their own (longer) retention window.
 *
 * The order is not negotiable: roll up first, delete second. A prune that ran before the
 * aggregate would be data loss; a delete wider than the aggregated window would lose whatever
 * landed in between. This class is the only place that sequence is expressed, which is what keeps
 * a cron or CLI caller from being able to get the order wrong.
 *
 * Day boundaries are computed here in PHP, in the reporting timezone {@see ReportingTimezone}
 * pins to Default Config, plus plain `\DateTimeImmutable`/`\DateTimeZone` arithmetic, never with
 * SQL's `DATE()` or `CONVERT_TZ()`: that is what keeps DST correct and keeps the module working on
 * an install whose MySQL has no timezone tables loaded (see task 003's schema comments). Pinning
 * the scope matters because this runs from cron, where the ambient store is the default store view
 * rather than the admin store the dashboard reads in.
 *
 * Every run holds a named lock ({@see LOCK_NAME}) for its whole duration. The daily write is
 * additive (see {@see rollUpAndDeleteRawRows()}), so two runs that read the same raw rows before
 * either deleted them would both add them to the day and double count it. A run that cannot take
 * the lock does nothing and says so; the run already holding it covers the same work.
 */
class UsageMaintenance
{
    /**
     * Timezone every window and comparison below is computed against; nothing here reads UTC.
     */
    private const UTC_TIMEZONE = 'UTC';

    /**
     * Name of the lock that keeps two roll-ups from running at once, across processes and hosts
     * that share the database (Magento's default lock backend is MySQL's `GET_LOCK()`).
     */
    public const LOCK_NAME = 'mageos_ai_usage_rollup';

    /**
     * Seconds to wait for {@see LOCK_NAME}: none. A run that finds the lock taken is redundant
     * with the one holding it, so waiting would only make it start on whatever that run leaves,
     * which the next scheduled run picks up anyway.
     */
    private const LOCK_TIMEOUT_SECONDS = 0;

    /**
     * @param UsageConfig $usageConfig Tells this class whether tracking is enabled at all and how
     *        many days of raw/daily history to keep.
     * @param UsageRecordRepositoryInterface $usageRecordRepository The raw log this class reads
     *        the oldest timestamp from and deletes rolled-up rows out of.
     * @param UsageRecordReportInterface $usageRecordReport The raw log's range aggregation this
     *        class reads each day's aggregates from.
     * @param UsageDailyRepositoryInterface $usageDailyRepository The daily roll-up this class
     *        writes aggregates into and prunes once they are older than the daily retention.
     * @param TimezoneInterface $timezone Source of the reporting timezone, read at Default Config
     *        through {@see ReportingTimezone}, so every day boundary below is a local calendar day
     *        rather than a UTC one, and the same one the dashboard draws periods in.
     * @param UsageTransactionInterface $transaction Makes each day's aggregate-then-delete a
     *        single committed step; see {@see rollUpAndDeleteRawRows()} for what a half-finished
     *        one would cost.
     * @param LockManagerInterface $lockManager Holds {@see LOCK_NAME} for the whole run, because
     *        the additive daily write would double count a day two overlapping runs both rolled up.
     * @param LoggerInterface $logger Notes a run skipped because another one held the lock, so an
     *        administrator chasing a missing roll-up can see why this one did nothing.
     */
    public function __construct(
        private readonly UsageConfig $usageConfig,
        private readonly UsageRecordRepositoryInterface $usageRecordRepository,
        private readonly UsageRecordReportInterface $usageRecordReport,
        private readonly UsageDailyRepositoryInterface $usageDailyRepository,
        private readonly TimezoneInterface $timezone,
        private readonly UsageTransactionInterface $transaction,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Runs the whole roll-up/prune sequence and reports what it did.
     *
     * Runs regardless of the tracking toggle (see the comment inside {@see runLocked()}).
     *
     * Returns a skipped result, touching nothing, when another run holds {@see LOCK_NAME}. That
     * is not an error: cron schedules overlap whenever one run outlasts the interval, and the run
     * holding the lock is already doing exactly this work. The lock is released even when the run
     * throws, so one failed run cannot block every later one.
     *
     * @return UsageMaintenanceResult
     */
    public function run(): UsageMaintenanceResult
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS)) {
            $this->logger->notice(sprintf(
                'AI usage roll-up skipped: another run holds the "%s" lock.',
                self::LOCK_NAME,
            ));

            return new UsageMaintenanceResult(0, 0, 0, isSkipped: true);
        }

        try {
            return $this->runLocked();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * The roll-up/prune sequence itself, only ever called while {@see LOCK_NAME} is held.
     *
     * @return UsageMaintenanceResult
     */
    private function runLocked(): UsageMaintenanceResult
    {
        // Deliberately not gated on the tracking toggle. Switching tracking off stops new rows
        // being recorded; it does not mean the rows already recorded should sit in the raw table
        // untouched forever. An install that tries the feature and turns it off would otherwise
        // keep whatever it had gathered at full detail indefinitely, never compacted into the
        // daily aggregates and never pruned — which is the opposite of what turning it off
        // implies. With nothing new arriving this is a cheap no-op once the backlog has drained.
        [$aggregatedRows, $deletedRows] = $this->rollUpAndDeleteRawRows();
        $prunedDailyRows = $this->pruneDailyRows();

        return new UsageMaintenanceResult($aggregatedRows, $deletedRows, $prunedDailyRows);
    }

    /**
     * Rolls up every whole local day older than the retention window, one day at a time, deleting
     * each day's raw rows immediately after its aggregate is safely written. Day-sized chunks
     * rather than one pass over the whole backlog is what keeps a store with months of history
     * from building one enormous aggregate result set in memory.
     *
     * The aggregate is *added* to whatever the day already holds, never written over it. Raw rows
     * are deleted once rolled up, so a later run that finds new raw rows for a day already rolled
     * up (a row that landed late, or a reporting timezone that moved the day boundaries) only ever
     * sees part of that day; replacing the stored total with that part would erase the rest for
     * good.
     *
     * Adding is only correct if each raw row is added exactly once, which is why each day's
     * aggregate and the delete behind it commit together: a delete that only got halfway would let
     * the next run add the surviving rows a second time. The lock {@see run()} holds covers the
     * other way to add a row twice, two runs reading it before either deleted it.
     *
     * @return array{0: int, 1: int} Rows aggregated, rows deleted.
     */
    private function rollUpAndDeleteRawRows(): array
    {
        $oldestRecordedAt = $this->usageRecordRepository->getOldestRecordedAt();
        if ($oldestRecordedAt === null) {
            return [0, 0];
        }

        $localTimezone = $this->localTimezone();
        $aggregatedRows = 0;
        $deletedRows = 0;

        foreach ($this->localDaysToRollUp($oldestRecordedAt, $localTimezone) as $localDay) {
            [$windowStart, $windowEnd] = $this->utcWindowForLocalDay($localDay, $localTimezone);
            $aggregateRows = $this->usageRecordReport->aggregateRange(
                $windowStart,
                $windowEnd,
                $localDay->format('Y-m-d')
            );
            if ($aggregateRows === []) {
                continue;
            }

            $deletedRows += $this->transaction->run(function () use ($aggregateRows, $windowEnd): int {
                $this->usageDailyRepository->saveAggregates($aggregateRows);

                return $this->usageRecordRepository->deleteOlderThan($windowEnd);
            });
            $aggregatedRows += array_sum(array_map(
                fn (array $row): int => (int) $row['calls'],
                $aggregateRows
            ));
        }

        return [$aggregatedRows, $deletedRows];
    }

    /**
     * Deletes daily rows past the (longer) daily retention window.
     *
     * Independent of whatever the raw roll-up just did: a store can have a stale daily backlog
     * even on a run where nothing new was rolled up.
     *
     * @return int Daily rows pruned.
     */
    private function pruneDailyRows(): int
    {
        $cutoff = (new \DateTimeImmutable('now', $this->localTimezone()))
            ->modify(sprintf('-%d days', $this->usageConfig->getDailyRetentionDays()));

        return $this->usageDailyRepository->deleteOlderThan($cutoff);
    }

    /**
     * Every whole local day strictly older than the retention window, oldest first, starting from
     * the oldest raw row on record. Never includes today: the retention window is always at least
     * one day, so its boundary always falls before today. Rolling up a day still in progress would
     * not lose anything with the additive write, but it would split one day across many runs for
     * no reason.
     *
     * @param \DateTimeImmutable $oldestRecordedAt
     * @param \DateTimeZone $localTimezone
     * @return array<int,\DateTimeImmutable>
     */
    private function localDaysToRollUp(
        \DateTimeImmutable $oldestRecordedAt,
        \DateTimeZone $localTimezone
    ): array {
        $cursor = new \DateTimeImmutable(
            $oldestRecordedAt->setTimezone($localTimezone)->format('Y-m-d'),
            $localTimezone
        );
        $boundary = new \DateTimeImmutable(
            (new \DateTimeImmutable('now', $localTimezone))
                ->modify(sprintf('-%d days', $this->usageConfig->getRetentionDays()))
                ->format('Y-m-d'),
            $localTimezone
        );

        $days = [];
        while ($cursor < $boundary) {
            $days[] = $cursor;
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * Converts one local calendar day into the half-open `[from, to)` UTC instant window
     * {@see UsageRecordReportInterface::aggregateRange()} and
     * {@see UsageRecordRepositoryInterface::deleteOlderThan()} compare `created_at` against.
     *
     * @param \DateTimeImmutable $localDay Any instant on the local day; only its `Y-m-d` portion
     *        is read.
     * @param \DateTimeZone $localTimezone
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function utcWindowForLocalDay(
        \DateTimeImmutable $localDay,
        \DateTimeZone $localTimezone
    ): array {
        $localStart = new \DateTimeImmutable($localDay->format('Y-m-d') . ' 00:00:00', $localTimezone);
        $localEnd = $localStart->modify('+1 day');
        $utcTimezone = new \DateTimeZone(self::UTC_TIMEZONE);

        return [$localStart->setTimezone($utcTimezone), $localEnd->setTimezone($utcTimezone)];
    }

    /**
     * The reporting timezone, Default Config's, never the ambient store's; see
     * {@see ReportingTimezone}.
     *
     * Asked for once per run rather than once per day, so a mid-run DST transition in the config
     * itself cannot shift the boundaries this run already computed.
     *
     * @return \DateTimeZone
     */
    private function localTimezone(): \DateTimeZone
    {
        return ReportingTimezone::resolve($this->timezone);
    }
}

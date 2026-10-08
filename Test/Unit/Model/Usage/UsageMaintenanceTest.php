<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage;

require_once __DIR__ . '/../../Stubs/InMemoryLockManager.php';
require_once __DIR__ . '/../../Stubs/RecordingLogger.php';
require_once __DIR__ . '/../../Stubs/DailyRowAddition.php';
require_once __DIR__ . '/../../Stubs/FakeUsageTransaction.php';

use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use MageOS\AiBase\Api\UsageRecordRepositoryInterface;
use MageOS\AiBase\Model\Usage\UsageConfig;
use MageOS\AiBase\Model\Usage\UsageMaintenance;
use MageOS\AiBase\Model\Usage\UsageRecordReportInterface;
use MageOS\AiBase\Model\Usage\UsageTransactionInterface;
use MageOS\AiBase\Test\Unit\Stubs\FakeUsageTransaction;
use MageOS\AiBase\Test\Unit\Stubs\DailyRowAddition;
use MageOS\AiBase\Test\Unit\Stubs\InMemoryLockManager;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\UsageMaintenance
 *
 * Exercises the roll-up/prune sequence against {@see FakeUsageRecordRepository} and
 * {@see FakeUsageDailyRepository}, in-memory stand-ins for the two repositories, per this
 * codebase's fakes-over-mocks convention. {@see FakeTimezone} pins the reporting timezone so the
 * day-bucketing tests are deterministic regardless of where the suite runs, and can answer a
 * different timezone for the ambient store scope to prove the default scope is the one read.
 * {@see InMemoryLockManager} stands in for Magento's lock backend.
 */
final class UsageMaintenanceTest extends TestCase
{
    private FakeUsageRecordRepository $usageRecordRepository;
    private FakeUsageDailyRepository $usageDailyRepository;
    private FakeScopeConfig $scopeConfig;
    private UsageMaintenance $subject;
    private FakeUsageTransaction $transaction;
    private InMemoryLockManager $lockManager;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->usageRecordRepository = new FakeUsageRecordRepository();
        $this->usageDailyRepository = new FakeUsageDailyRepository();
        $this->scopeConfig = new FakeScopeConfig();
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', true);
        $this->scopeConfig->setValue('mageos_ai/usage/retention_days', '30');
        $this->scopeConfig->setValue('mageos_ai/usage/daily_retention_days', '730');

        $this->transaction = new FakeUsageTransaction();
        $this->usageRecordRepository->reportUnitsTo($this->transaction);
        $this->usageDailyRepository->reportUnitsTo($this->transaction);
        $this->lockManager = new InMemoryLockManager();
        $this->logger = new RecordingLogger();
        $this->subject = $this->subjectInTimezone(new FakeTimezone('UTC'));
    }

    public function test_it_aggregates_raw_rows_older_than_the_retention_window_into_daily_totals(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));

        $this->subject->run();

        self::assertCount(1, $this->usageDailyRepository->getStoredRows());
    }

    public function test_it_groups_aggregates_by_day_service_row_model_consumer_and_store(): void
    {
        $this->usageRecordRepository->addRow($this->row([
            'created_at' => $this->daysAgo(60),
            'service_id' => '_row1',
            'model' => 'claude-sonnet',
            'consumer' => 'chat',
            'store_id' => 0,
        ]));
        $this->usageRecordRepository->addRow($this->row([
            'created_at' => $this->daysAgo(60),
            'service_id' => '_row2',
            'model' => 'gpt-5',
            'consumer' => 'docs_search',
            'store_id' => 1,
        ]));

        $this->subject->run();

        $stored = $this->usageDailyRepository->getStoredRows();
        self::assertCount(2, $stored);
        self::assertSame(['_row1', '_row2'], array_column($stored, 'service_id'));
        self::assertSame(['claude-sonnet', 'gpt-5'], array_column($stored, 'model'));
        self::assertSame(['chat', 'docs_search'], array_column($stored, 'consumer'));
        self::assertSame([0, 1], array_column($stored, 'store_id'));
    }

    public function test_it_sums_the_token_counts_of_every_raw_row_in_a_group(): void
    {
        $this->usageRecordRepository->addRow($this->row([
            'created_at' => $this->daysAgo(60),
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
        ]));
        $this->usageRecordRepository->addRow($this->row([
            'created_at' => $this->daysAgo(60),
            'input_tokens' => 20,
            'output_tokens' => 8,
            'total_tokens' => 28,
        ]));

        $this->subject->run();

        $stored = $this->usageDailyRepository->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame(30, $stored[0]['input_tokens']);
        self::assertSame(13, $stored[0]['output_tokens']);
        self::assertSame(43, $stored[0]['total_tokens']);
    }

    public function test_it_counts_the_number_of_calls_in_a_group(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));

        $this->subject->run();

        $stored = $this->usageDailyRepository->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame(3, $stored[0]['calls']);
    }

    public function test_it_leaves_the_current_day_untouched(): void
    {
        $this->scopeConfig->setValue('mageos_ai/usage/retention_days', '1');
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(0)]));

        $this->subject->run();

        self::assertCount(0, $this->usageDailyRepository->getStoredRows());
        self::assertCount(1, $this->usageRecordRepository->getRemainingRows());
    }

    public function test_it_writes_a_day_aggregate_and_deletes_its_raw_rows_in_one_unit(): void
    {
        // The aggregate is added to the day's row, so a delete that only got halfway would let
        // the next run add the surviving rows a second time. Both halves therefore share one
        // transaction, and the database's rollback covers them together.
        $this->usageRecordRepository->addRow($this->row(['created_at' => '2026-01-10 03:00:00']));
        $this->usageRecordRepository->addRow($this->row(['created_at' => '2026-01-11 03:00:00']));

        $this->subject->run();

        self::assertSame(
            [['saveAggregates', 'deleteOlderThan'], ['saveAggregates', 'deleteOlderThan']],
            $this->transaction->getUnits()
        );
    }

    public function test_it_leaves_the_daily_prune_outside_the_rollup_transaction(): void
    {
        // Pruning old daily rows is independent of any day being rolled up, and holding the
        // roll-up's locks across it would only lengthen the transaction for no gain.
        $this->usageRecordRepository->addRow($this->row(['created_at' => '2026-01-10 03:00:00']));

        $this->subject->run();

        self::assertNotContains('deleteDailyOlderThan', array_merge(...$this->transaction->getUnits()));
    }

    public function test_it_buckets_a_call_made_late_in_the_evening_into_the_reporting_timezone_day(): void
    {
        $subject = $this->subjectInTimezone(new FakeTimezone('America/New_York'));
        $this->usageRecordRepository->addRow($this->row(['created_at' => '2026-01-10 03:00:00']));

        $subject->run();

        $stored = $this->usageDailyRepository->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame('2026-01-09', $stored[0]['usage_date']);
    }

    public function test_it_buckets_days_in_the_default_scope_timezone_rather_than_the_ambient_store_one(): void
    {
        // In cron the ambient store is the default store view, whose timezone can differ from
        // Default Config's; the dashboard reads periods at Default Config, so the roll-up must too.
        $subject = $this->subjectInTimezone(new FakeTimezone('America/New_York', 'Asia/Tokyo'));
        $this->usageRecordRepository->addRow($this->row(['created_at' => '2026-01-10 03:00:00']));

        $subject->run();

        self::assertSame('2026-01-09', $this->usageDailyRepository->getStoredRows()[0]['usage_date']);
    }

    public function test_it_adds_raw_rows_for_an_already_rolled_up_day_to_that_day_total(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60, '08:00:00'), 'total_tokens' => 15]));
        $this->subject->run();
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60, '09:00:00'), 'total_tokens' => 30]));

        $this->subject->run();

        $stored = $this->usageDailyRepository->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame(2, $stored[0]['calls']);
        self::assertSame(45, $stored[0]['total_tokens']);
    }

    public function test_it_skips_the_run_without_touching_anything_when_another_run_holds_the_lock(): void
    {
        $this->lockManager->givenLockHeldElsewhere(UsageMaintenance::LOCK_NAME);
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageDailyRepository->saveAggregates([$this->dailyRow(['usage_date' => $this->localDate(900)])]);

        $result = $this->subject->run();

        self::assertTrue($result->isSkipped());
        self::assertCount(1, $this->usageRecordRepository->getRemainingRows());
        self::assertCount(1, $this->usageDailyRepository->getStoredRows());
    }

    public function test_it_logs_a_notice_when_it_skips_because_another_run_holds_the_lock(): void
    {
        $this->lockManager->givenLockHeldElsewhere(UsageMaintenance::LOCK_NAME);

        $this->subject->run();

        $records = $this->logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame('notice', $records[0]['level']);
        self::assertStringContainsString(UsageMaintenance::LOCK_NAME, $records[0]['message']);
    }

    public function test_it_takes_the_rollup_lock_and_releases_it_after_the_run(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));

        $result = $this->subject->run();

        self::assertFalse($result->isSkipped());
        self::assertSame([UsageMaintenance::LOCK_NAME], $this->lockManager->getRequestedNames());
        self::assertFalse($this->lockManager->isLocked(UsageMaintenance::LOCK_NAME));
    }

    public function test_it_releases_the_rollup_lock_when_the_run_fails(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageRecordRepository->throwOnAggregate(new \RuntimeException('aggregate query failed'));

        try {
            $this->subject->run();
            self::fail('Expected the aggregate failure to be rethrown.');
        } catch (\RuntimeException) {
        }

        self::assertFalse($this->lockManager->isLocked(UsageMaintenance::LOCK_NAME));
    }

    public function test_it_deletes_the_raw_rows_it_aggregated(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(61)]));

        $this->subject->run();

        self::assertCount(0, $this->usageRecordRepository->getRemainingRows());
    }

    public function test_it_does_not_delete_raw_rows_it_did_not_aggregate(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(5)]));

        $this->subject->run();

        $remaining = $this->usageRecordRepository->getRemainingRows();
        self::assertCount(1, $remaining);
        self::assertSame($this->daysAgo(5), $remaining[0]['created_at']);
    }

    public function test_it_prunes_daily_rows_past_the_daily_retention_period(): void
    {
        $this->scopeConfig->setValue('mageos_ai/usage/daily_retention_days', '30');
        $this->usageDailyRepository->saveAggregates([$this->dailyRow(['usage_date' => $this->localDate(60)])]);
        $this->usageDailyRepository->saveAggregates([$this->dailyRow([
            'usage_date' => $this->localDate(5),
            'consumer' => 'docs_search',
        ])]);

        $this->subject->run();

        $stored = $this->usageDailyRepository->getStoredRows();
        self::assertCount(1, $stored);
        self::assertSame($this->localDate(5), $stored[0]['usage_date']);
    }

    public function test_it_keeps_cached_token_totals_null_when_no_row_in_the_group_reported_them(): void
    {
        $this->usageRecordRepository->addRow($this->row([
            'created_at' => $this->daysAgo(60),
            'cache_read_tokens' => null,
            'reasoning_tokens' => null,
        ]));
        $this->usageRecordRepository->addRow($this->row([
            'created_at' => $this->daysAgo(60),
            'cache_read_tokens' => null,
            'reasoning_tokens' => null,
        ]));

        $this->subject->run();

        $stored = $this->usageDailyRepository->getStoredRows();
        self::assertCount(1, $stored);
        self::assertNull($stored[0]['cache_read_tokens']);
        self::assertNull($stored[0]['reasoning_tokens']);
    }

    public function test_it_is_safe_to_run_twice_in_a_row_with_the_same_result(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60), 'total_tokens' => 15]));
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(90), 'total_tokens' => 45]));

        $firstRun = $this->subject->run();
        $storedAfterFirstRun = $this->usageDailyRepository->getStoredRows();
        $secondRun = $this->subject->run();

        self::assertSame(2, $firstRun->getAggregatedRows());
        self::assertSame(0, $secondRun->getAggregatedRows());
        self::assertSame(0, $secondRun->getDeletedRows());
        self::assertSame($storedAfterFirstRun, $this->usageDailyRepository->getStoredRows());
    }

    public function test_it_still_drains_the_backlog_when_tracking_is_disabled(): void
    {
        $this->scopeConfig->setFlag('mageos_ai/usage/enabled', false);
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageDailyRepository->saveAggregates([$this->dailyRow(['usage_date' => $this->localDate(900)])]);

        $result = $this->subject->run();

        // Switching tracking off stops new rows being recorded; it does not mean the rows already
        // gathered should sit at full detail forever, never compacted and never pruned.
        self::assertSame(1, $result->getAggregatedRows());
        self::assertSame(1, $result->getDeletedRows());
        self::assertSame(1, $result->getPrunedDailyRows());
        self::assertCount(0, $this->usageRecordRepository->getRemainingRows());
    }

    public function test_it_reports_how_many_rows_it_aggregated_deleted_and_pruned(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60)]));
        $this->usageDailyRepository->saveAggregates([$this->dailyRow(['usage_date' => $this->localDate(900)])]);

        $result = $this->subject->run();

        self::assertSame(2, $result->getAggregatedRows());
        self::assertSame(2, $result->getDeletedRows());
        self::assertSame(1, $result->getPrunedDailyRows());
    }

    public function test_it_preserves_total_token_counts_across_the_rollup(): void
    {
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(60), 'total_tokens' => 15]));
        $this->usageRecordRepository->addRow($this->row([
            'created_at' => $this->daysAgo(61),
            'consumer' => 'docs_search',
            'total_tokens' => 30,
        ]));
        $this->usageRecordRepository->addRow($this->row(['created_at' => $this->daysAgo(90), 'total_tokens' => 45]));

        $this->subject->run();

        $totalAfterRollup = array_sum(array_column($this->usageDailyRepository->getStoredRows(), 'total_tokens'));
        self::assertSame(90, $totalAfterRollup);
    }

    private function subjectInTimezone(FakeTimezone $timezone): UsageMaintenance
    {
        return new UsageMaintenance(
            new UsageConfig($this->scopeConfig),
            $this->usageRecordRepository,
            $this->usageRecordRepository,
            $this->usageDailyRepository,
            $timezone,
            $this->transaction,
            $this->lockManager,
            $this->logger,
        );
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function dailyRow(array $overrides = []): array
    {
        return array_merge(
            [
                'usage_date' => $this->localDate(60),
                'service_id' => '_row1',
                'service_code' => 'anthropic',
                'model' => 'claude-sonnet',
                'consumer' => 'chat',
                'store_id' => 0,
                'calls' => 1,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'cache_read_tokens' => null,
                'reasoning_tokens' => null,
            ],
            $overrides
        );
    }

    private function localDate(int $daysAgo): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $daysAgo))
            ->format('Y-m-d');
    }

    /**
     * @param array<string,int|string|null> $overrides
     * @return array<string,int|string|null>
     */
    private function row(array $overrides = []): array
    {
        return array_merge(
            [
                'created_at' => $this->daysAgo(60),
                'service_id' => '_row1',
                'service_code' => 'anthropic',
                'model' => 'claude-sonnet',
                'consumer' => 'chat',
                'store_id' => 0,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'cache_read_tokens' => null,
                'reasoning_tokens' => null,
            ],
            $overrides
        );
    }

    private function daysAgo(int $days, string $time = '12:00:00'): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $days))
            ->format('Y-m-d') . ' ' . $time;
    }
}

/**
 * In-memory stand-in for {@see UsageRecordRepositoryInterface} and
 * {@see UsageRecordReportInterface}, the two contracts the real raw-log repository implements and
 * {@see UsageMaintenance} receives separately; a test passes the same instance for both. Rows
 * are added directly through {@see addRow()} with an explicit `created_at` string, rather than
 * through {@see save()}, because these tests need full control over historical timestamps that
 * {@see save()} deliberately does not expose (the real repository lets the database assign
 * `created_at`).
 *
 * `getList()`, `save()`, `sumRange()`, `groupRange()` and `getDistinctConsumers()` are not
 * exercised by {@see UsageMaintenance} and throw, so a test that accidentally depends on one of
 * them fails loudly instead of silently returning a meaningless default.
 */
final class FakeUsageRecordRepository implements UsageRecordRepositoryInterface, UsageRecordReportInterface
{
    private ?FakeUsageTransaction $unitLog = null;

    public function reportUnitsTo(FakeUsageTransaction $unitLog): void
    {
        $this->unitLog = $unitLog;
    }

    /**
     * @var array<int,array<string,int|string|null>>
     */
    private array $rowsById = [];

    private int $nextId = 1;

    private ?\Throwable $aggregateFailure = null;

    /**
     * @param array<string,int|string|null> $row
     */
    public function addRow(array $row): void
    {
        $this->rowsById[$this->nextId++] = $row;
    }

    /**
     * Makes the next {@see aggregateRange()} throw $failure, standing in for a failing query.
     */
    public function throwOnAggregate(\Throwable $failure): void
    {
        $this->aggregateFailure = $failure;
    }

    public function save(\MageOS\AiBase\Api\Data\UsageRecordInterface $record): void
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function getRemainingRows(): array
    {
        return array_values($this->rowsById);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        $this->unitLog?->record('deleteOlderThan');
        $cutoffString = $cutoff->format('Y-m-d H:i:s');
        $idsToDelete = array_keys(array_filter(
            $this->rowsById,
            fn (array $row): bool => (string) $row['created_at'] < $cutoffString
        ));

        foreach ($idsToDelete as $id) {
            unset($this->rowsById[$id]);
        }

        return count($idsToDelete);
    }

    public function getOldestRecordedAt(): ?\DateTimeImmutable
    {
        if ($this->rowsById === []) {
            return null;
        }

        $oldest = min(array_map(fn (array $row): string => (string) $row['created_at'], $this->rowsById));

        return new \DateTimeImmutable($oldest, new \DateTimeZone('UTC'));
    }

    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array
    {
        if ($this->aggregateFailure !== null) {
            throw $this->aggregateFailure;
        }

        $groups = [];
        foreach ($this->rowsInWindow($from, $to) as $row) {
            $key = implode('|', [$row['service_id'], $row['model'], $row['consumer'], $row['store_id']]);
            $groups[$key][] = $row;
        }

        return array_values(array_map(
            fn (array $rows): array => array_merge(
                [
                    'usage_date' => $usageDate,
                    'service_id' => $rows[0]['service_id'],
                    'service_code' => $rows[0]['service_code'],
                    'model' => $rows[0]['model'],
                    'consumer' => $rows[0]['consumer'],
                    'store_id' => $rows[0]['store_id'],
                ],
                $this->totals($rows)
            ),
            $groups
        ));
    }

    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function getDistinctConsumers(): array
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    private function rowsInWindow(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $fromString = $from->format('Y-m-d H:i:s');
        $toString = $to->format('Y-m-d H:i:s');

        return array_values(array_filter(
            $this->rowsById,
            fn (array $row): bool => (string) $row['created_at'] >= $fromString
                && (string) $row['created_at'] < $toString
        ));
    }

    /**
     * @param array<int,array<string,int|string|null>> $rows
     * @return array<string,int|null>
     */
    private function totals(array $rows): array
    {
        $totals = [
            'calls' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_read_tokens' => null,
            'reasoning_tokens' => null,
        ];

        foreach ($rows as $row) {
            $totals['calls']++;
            $totals['input_tokens'] += (int) $row['input_tokens'];
            $totals['output_tokens'] += (int) $row['output_tokens'];
            $totals['total_tokens'] += (int) $row['total_tokens'];
            if ($row['cache_read_tokens'] !== null) {
                $totals['cache_read_tokens'] = ($totals['cache_read_tokens'] ?? 0) + (int) $row['cache_read_tokens'];
            }
            if ($row['reasoning_tokens'] !== null) {
                $totals['reasoning_tokens'] = ($totals['reasoning_tokens'] ?? 0) + (int) $row['reasoning_tokens'];
            }
        }

        return $totals;
    }

    /**
     * Not exercised by this test's subject; present so the fake satisfies the interface.
     *
     * @return array<int,array<string,int|string|null>>
     */
    public function seriesRangeGrouped(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        string $groupBy,
        ?int $storeId = null
    ): array {
        return [];
    }
}

/**
 * In-memory stand-in for {@see UsageDailyRepositoryInterface}, keyed the same way the real
 * table's unique constraint is (`usage_date`, `service_id`, `model`, `consumer`, `store_id`), so
 * {@see saveAggregates()} adds a colliding row's counts to the stored ones the same way the real
 * insert-on-duplicate statement does, keeping a nullable count null only when neither side has one.
 *
 * `getList()` is not exercised by {@see UsageMaintenance} and throws.
 */
final class FakeUsageDailyRepository implements UsageDailyRepositoryInterface
{
    /**
     * @var array<string,array<string,int|string|null>>
     */
    private array $rowsByKey = [];

    private ?FakeUsageTransaction $unitLog = null;

    public function reportUnitsTo(FakeUsageTransaction $unitLog): void
    {
        $this->unitLog = $unitLog;
    }

    public function saveAggregates(array $rows): void
    {
        $this->unitLog?->record('saveAggregates');
        foreach ($rows as $row) {
            $key = implode('|', [
                $row['usage_date'],
                $row['service_id'],
                $row['model'],
                $row['consumer'],
                $row['store_id'],
            ]);
            $this->rowsByKey[$key] = isset($this->rowsByKey[$key])
                ? DailyRowAddition::add($this->rowsByKey[$key], $row)
                : $row;
        }
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff): int
    {
        $cutoffDate = $cutoff->format('Y-m-d');
        $keysToDelete = array_keys(array_filter(
            $this->rowsByKey,
            fn (array $row): bool => (string) $row['usage_date'] < $cutoffDate
        ));

        foreach ($keysToDelete as $key) {
            unset($this->rowsByKey[$key]);
        }

        return count($keysToDelete);
    }

    /**
     * @return array<int,array<string,int|string|null>>
     */
    public function getStoredRows(): array
    {
        return array_values($this->rowsByKey);
    }
}

/**
 * In-memory stand-in for {@see ScopeConfigInterface}, holding plain values and flags a test sets
 * through {@see setValue()} / {@see setFlag()} rather than through a `->method()->willReturn()`
 * mock chain.
 */
final class FakeScopeConfig implements ScopeConfigInterface
{
    /**
     * @var array<string,string>
     */
    private array $values = [];

    /**
     * @var array<string,bool>
     */
    private array $flags = [];

    public function setValue(string $path, string $value): void
    {
        $this->values[$path] = $value;
    }

    public function setFlag(string $path, bool $value): void
    {
        $this->flags[$path] = $value;
    }

    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->flags[$path] ?? false;
    }
}

/**
 * In-memory stand-in for {@see TimezoneInterface} that only implements
 * {@see getConfigTimezone()}, the one method {@see UsageMaintenance} calls: it computes every day
 * boundary itself from the returned timezone name with plain `\DateTimeImmutable`/`\DateTimeZone`,
 * never through this class's other conversion helpers. Every other method throws, so a test that
 * accidentally depends on one fails loudly instead of silently returning a meaningless default.
 */
final class FakeTimezone implements TimezoneInterface
{
    /**
     * @param string $timezoneName What Default Config answers: the reporting timezone.
     * @param string|null $ambientStoreTimezone What any other scope answers, standing in for a
     *        store view with its own timezone, so a test can prove the subject asks for Default
     *        Config explicitly; null answers $timezoneName for every scope.
     */
    public function __construct(
        private readonly string $timezoneName,
        private readonly ?string $ambientStoreTimezone = null,
    ) {
    }

    public function getConfigTimezone($scopeType = null, $scopeCode = null)
    {
        if ($scopeType === \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            return $this->timezoneName;
        }

        return $this->ambientStoreTimezone ?? $this->timezoneName;
    }

    public function getDefaultTimezonePath()
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function getDefaultTimezone()
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function getDateFormat($type = \IntlDateFormatter::SHORT)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function getDateFormatWithLongYear()
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function getTimeFormat($type = null)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function getDateTimeFormat($type)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function scopeDate($scope = null, $date = null, $includeTime = false)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function scopeTimeStamp($scope = null)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null)
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function formatDateTime(
        $date,
        $dateType = \IntlDateFormatter::SHORT,
        $timeType = \IntlDateFormatter::SHORT,
        $locale = null,
        $timezone = null,
        $pattern = null
    ) {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }

    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
    {
        throw new \LogicException('Not needed by UsageMaintenanceTest.');
    }
}

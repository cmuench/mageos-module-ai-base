<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Api\UsageDailyRepositoryInterface;

/**
 * Range aggregation over `mageos_ai_usage_log`, the raw per-call table, for the usage dashboard
 * ({@see UsageStats}) and the daily roll-up ({@see UsageMaintenance}).
 *
 * Internal to this module, not part of its public API: every method returns raw row arrays shaped
 * after the table's columns, so this interface changes whenever the schema does and may change in
 * any release. The public read contract for usage figures is
 * {@see \MageOS\AiBase\Api\UsageStatsInterface}; the public persistence contract for the table
 * is {@see \MageOS\AiBase\Api\UsageRecordRepositoryInterface}.
 */
interface UsageRecordReportInterface
{
    /**
     * Value of {@see groupRange()}'s `$groupBy` argument that groups a window by consumer.
     */
    public const GROUP_BY_CONSUMER = 'consumer';

    /**
     * Value of {@see groupRange()}'s `$groupBy` argument that groups a window by
     * {@see UsageRecordInterface::getServiceId()}.
     */
    public const GROUP_BY_SERVICE = 'service_id';

    /**
     * Aggregates every row in the half-open window `[$from, $to)`, one result row per
     * (`service_id`, `service_code`, `model`, `consumer`, `store_id`) grouping key.
     *
     * $from and $to are absolute UTC instants, never a calendar date: this method never calls
     * `DATE()` or `CONVERT_TZ()`, so converting the window to the store's local day boundaries is
     * the caller's job before it gets here (task 003's schema comments explain why).
     *
     * Each result row carries `calls` (the number of raw rows that matched the group), `failed_calls`
     * and the five token counts summed, and the `usage_date` label $usageDate the caller already
     * computed for the window, so the shape is exactly what
     * {@see UsageDailyRepositoryInterface::saveAggregates()} expects one of its rows to look like
     * without any further reshaping in between.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string $usageDate Local calendar date label ($from's window), copied onto every row.
     * @return array<int,array<string,int|string|null>>
     */
    public function aggregateRange(\DateTimeInterface $from, \DateTimeInterface $to, string $usageDate): array;

    /**
     * Totals every token count across raw rows in `[$from, $to)`, optionally narrowed to one consumer.
     *
     * Returns an associative array with keys `calls`, `failed_calls`, `input_tokens`,
     * `output_tokens`, `total_tokens`, `cache_read_tokens`, `cache_write_tokens`,
     * `reasoning_tokens`. `calls`, `failed_calls` and the three plain token counts are always an
     * int, zero when nothing matched. `cache_read_tokens`, `cache_write_tokens` and
     * `reasoning_tokens` stay null when no matching row ever reported them, rather than becoming a
     * misleading zero.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string|null $consumer
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<string,int|null>
     */
    public function sumRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?string $consumer = null,
        ?int $storeId = null
    ): array;

    /**
     * Totals the same window as {@see sumRange()}, grouped by consumer or by service row.
     *
     * $groupBy is one of {@see GROUP_BY_CONSUMER} or {@see GROUP_BY_SERVICE}. Returns a list of
     * associative arrays, each carrying the group's own value under the $groupBy column name plus
     * the same token-count keys as {@see sumRange()}, ordered by `total_tokens` descending so a
     * caller can read off the biggest consumer or service row first without sorting again.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string $groupBy
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>>
     */
    public function groupRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $groupBy,
        ?int $storeId = null
    ): array;

    /**
     * Totals the same window as {@see sumRange()}, bucketed per store-timezone day or month.
     *
     * $granularity reuses {@see UsageDailyReportInterface::GRANULARITY_DAY} or
     * {@see UsageDailyReportInterface::GRANULARITY_MONTH} rather than declaring parallel
     * constants of its own, so a caller merging rows from both tables (task 013's
     * `UsageStats`) never has to reconcile two vocabularies for the same concept.
     *
     * Unlike {@see UsageDailyReportInterface::seriesRange()}, every bucket between $from and
     * $to is returned, including one with no matching rows at all: the daily table can group by
     * its own `usage_date` column and simply never produces a row for a day nothing was rolled up
     * on, but this table only has a UTC `created_at`, so there is no column to group by. Each
     * bucket's local start and end is resolved once through
     * {@see \Magento\Framework\Stdlib\DateTime\TimezoneInterface}, converted to UTC, and queried
     * as its own half-open `created_at >= start AND created_at < end` aggregate — never
     * `DATE()` or `CONVERT_TZ()`, which is exactly what task 003's schema comments forbid. A
     * calendar day that is 23 or 25 hours long over a daylight-saving transition is still exactly
     * one bucket, because the boundary arithmetic is plain `\DateTimeImmutable::modify()` in the
     * store's real timezone rather than a fixed offset.
     *
     * One query per bucket is acceptable here only because the caller is expected to bound
     * $from/$to to the small window the raw table actually covers (the raw retention window, 30
     * days by default) rather than pass a whole reporting period through unbounded.
     *
     * Returns a list of associative arrays ordered by `period` ascending, each carrying `period`
     * (`Y-m-d` for a day bucket, `Y-m` for a month bucket) plus the same token-count keys as
     * {@see sumRange()}.
     *
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @param string $granularity
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>>
     */
    public function seriesRange(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        ?int $storeId = null
    ): array;

    /**
     * The same window as {@see seriesRange()}, split a second time by a grouping column.
     *
     * One row per (bucket, group) pair actually present — unlike {@see seriesRange()}, a bucket
     * with no rows contributes nothing here, and a caller wanting a dense series fills the gaps
     * itself from the bucket list it already knows.
     *
     * @param \DateTimeInterface $from Inclusive
     * @param \DateTimeInterface $to Exclusive
     * @param string $granularity One of the {@see UsageDailyReportInterface} GRANULARITY_* constants
     * @param string $groupBy One of the GROUP_BY_* constants
     * @param int|null $storeId Narrow to one store, or `null` for every store
     * @return array<int,array<string,int|string|null>> Rows carrying `period`, the grouping column
     *         and the aggregate totals
     */
    public function seriesRangeGrouped(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $granularity,
        string $groupBy,
        ?int $storeId = null
    ): array;
}

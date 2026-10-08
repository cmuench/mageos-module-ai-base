<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

/**
 * Range aggregation over `mageos_ai_usage_daily`, the roll-up table, for the usage dashboard
 * ({@see UsageStats}).
 *
 * Internal to this module, not part of its public API: every method returns raw row arrays shaped
 * after the table's columns, and the string constants below are the internal counterpart of
 * {@see \MageOS\AiBase\Api\Data\Granularity}, so this interface changes whenever the schema
 * does and may change in any release. The public read contract for usage figures is
 * {@see \MageOS\AiBase\Api\UsageStatsInterface}; the public persistence contract for the table
 * is {@see \MageOS\AiBase\Api\UsageDailyRepositoryInterface}.
 */
interface UsageDailyReportInterface
{
    /**
     * Value of {@see groupRange()}'s `$groupBy` argument that groups a window by consumer.
     */
    public const GROUP_BY_CONSUMER = 'consumer';

    /**
     * Value of {@see groupRange()}'s `$groupBy` argument that groups a window by
     * {@see \MageOS\AiBase\Api\Data\UsageRecordInterface::getServiceId()}.
     */
    public const GROUP_BY_SERVICE = 'service_id';

    /**
     * Value of {@see seriesRange()}'s `$granularity` argument that buckets a window per day.
     */
    public const GRANULARITY_DAY = 'day';

    /**
     * Value of {@see seriesRange()}'s `$granularity` argument that buckets a window per month.
     */
    public const GRANULARITY_MONTH = 'month';

    /**
     * Totals every token count across daily rows in the half-open window `[$from, $to)`.
     *
     * Optionally narrowed to one consumer. `$from` and `$to` are `DateTimeInterface` for symmetry
     * with the raw repository's window arguments, but only their date portion is read:
     * `usage_date` is already a local calendar date computed once at roll-up time (task 011), so
     * there is no timezone conversion left to do here and this method never calls `DATE()` or
     * `CONVERT_TZ()`.
     *
     * Returns an associative array with keys `calls`, `failed_calls`, `input_tokens`,
     * `output_tokens`, `total_tokens`, `cache_read_tokens`, `cache_write_tokens`,
     * `reasoning_tokens`. `calls`, `failed_calls` and the three plain token counts are always an
     * int, zero when nothing matched. `cache_read_tokens`, `cache_write_tokens` and
     * `reasoning_tokens` stay null when no matching row ever reported them, the same nullable
     * convention the raw table uses, rather than becoming a misleading zero.
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
     * Totals the same window as {@see sumRange()}, bucketed per day or per month, for the series.
     *
     * $granularity is one of {@see GRANULARITY_DAY} or {@see GRANULARITY_MONTH}. Returns a list of
     * associative arrays ordered by `period` ascending, each carrying `period` (`Y-m-d` for a day
     * bucket, `Y-m` for a month bucket) plus the same token-count keys as {@see sumRange()}. Only
     * periods with at least one matching row are returned; filling the gaps between them with zero
     * is the caller's job (task 013), since this method never hydrates a period it has no data for.
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
     * @param string $granularity One of the GRANULARITY_* constants
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

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;

/**
 * Persistence contract for `mageos_ai_usage_daily`, the roll-up the cron writes from the raw
 * usage log before pruning it.
 *
 * Range aggregation over this table (the totals, breakdowns and series the usage dashboard
 * reads) is deliberately not part of this contract: it returns raw row arrays shaped after the
 * table's columns, so it lives on the internal
 * {@see \MageOS\AiBase\Model\Usage\UsageDailyReportInterface} instead. The public read
 * contract for usage figures is {@see \MageOS\AiBase\Api\UsageStatsInterface}.
 *
 * Every SQL statement lives in {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageDaily}; this
 * class only assembles rows and delegates. That split is what keeps this repository unit-testable
 * against a small fake instead of against `Magento\Framework\DB\Adapter\AdapterInterface`, which
 * has over a hundred methods and is not realistically fakeable.
 */
interface UsageDailyRepositoryInterface
{
    /**
     * Writes a batch of daily aggregate rows in one insert-or-update statement.
     *
     * One statement for the whole batch rather than one round trip per row.
     *
     * A row matching an existing one on (`usage_date`, `service_id`, `model`, `consumer`,
     * `store_id`), the unique constraint task 003 declared, has its counts *added* to the stored
     * ones, never written over them. The roll-up deletes raw rows once they are aggregated, so a
     * later run that finds new raw rows for a day already rolled up (a row that landed late, or a
     * reporting timezone change that moved the day boundaries) only sees part of that day; writing
     * that part over the stored total would erase the rest for good. A nullable token count stays
     * null only when neither side reported it. The flip side is that saving the same rows twice
     * counts them twice, which is why {@see \MageOS\AiBase\Model\Usage\UsageMaintenance} deletes
     * the raw rows in the same transaction and holds a lock across the whole run.
     *
     * Each row is an associative array carrying every non-identity column of
     * `mageos_ai_usage_daily`: `usage_date`, `service_id`, `service_code`, `model`, `consumer`,
     * `store_id`, `calls`, `failed_calls`, `input_tokens`, `output_tokens`, `total_tokens`,
     * `cache_read_tokens`, `cache_write_tokens`, `reasoning_tokens`. Plain arrays rather than a
     * value object because this is exactly the
     * shape {@see \MageOS\AiBase\Model\Usage\UsageRecordReportInterface::aggregateRange()}
     * (task 005) produces, and round-tripping it through a DTO here would buy nothing.
     *
     * @param array<int,array<string,int|string|null>> $rows
     * @return void
     */
    public function saveAggregates(array $rows): void;

    /**
     * Lists daily roll-up rows matching a search criteria.
     *
     * For administrative listing and filtering, unlike the stats aggregation methods on
     * {@see \MageOS\AiBase\Model\Usage\UsageDailyReportInterface}, which never hydrate rows.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Deletes daily rows strictly older than $cutoff's date and reports how many were removed.
     *
     * Unlike the raw table's equivalent (task 005), this table holds one row per grouping key per
     * day rather than one row per call, so a store with years of history still has a small table
     * and needs no batching to prune safely.
     *
     * @param \DateTimeInterface $cutoff
     * @return int Number of rows deleted
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int;
}

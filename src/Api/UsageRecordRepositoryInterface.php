<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use MageOS\AiBase\Api\Data\UsageRecordInterface;
use MageOS\AiBase\Api\UsageDailyRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;

/**
 * Persistence contract for `mageos_ai_usage_log`, the raw per-call table the recording decorator
 * writes to and the daily roll-up (task 011) reads from before it prunes rows out of it.
 *
 * Range aggregation over this table (the totals, breakdowns and series the usage dashboard and
 * the daily roll-up read) is deliberately not part of this contract: it returns raw row arrays
 * shaped after the table's columns, so it lives on the internal
 * {@see \MageOS\AiBase\Model\Usage\UsageRecordReportInterface} instead. The public read
 * contract for usage figures is {@see \MageOS\AiBase\Api\UsageStatsInterface}.
 *
 * Every SQL statement lives in {@see \MageOS\AiBase\Model\ResourceModel\Usage\UsageLog}; this
 * class only assembles rows and delegates. That split is what keeps this repository unit-testable
 * against a small fake instead of against `Magento\Framework\DB\Adapter\AdapterInterface`, which
 * has over a hundred methods and is not realistically fakeable.
 *
 * {@see getList()} is the one exception to "this class only sees {@see UsageRecordInterface}": it
 * exists for the admin grid (task 015), which needs the `AbstractModel`/collection shape to filter
 * and page, not the value object the rest of the module sees. That is the two shapes this module
 * keeps on purpose — see {@see \MageOS\AiBase\Model\Usage\UsageLog}'s class docblock.
 */
interface UsageRecordRepositoryInterface
{
    /**
     * Persists one recorded call.
     *
     * Void, not the saved record: nothing in this module reads the row straight back after
     * writing it, so returning one would only invite a caller to depend on a round trip nothing
     * here needs. The database assigns the id and the `created_at` timestamp; a caller that needs
     * either reads the table back through {@see getList()}.
     *
     * @param UsageRecordInterface $record
     * @return void
     */
    public function save(UsageRecordInterface $record): void;

    /**
     * Lists raw usage rows matching a search criteria, for the admin grid (task 015).
     *
     * Returns {@see \MageOS\AiBase\Model\Usage\UsageLog} items, not
     * {@see UsageRecordInterface}: a grid filters and pages through Magento's collection
     * machinery, which is what that class exists for.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * Deletes rows strictly older than $cutoff and reports how many were removed.
     *
     * Deletes in bounded batches rather than one unbounded statement: a store that ran tracking
     * for months has a raw-log table the prune has to chew through without holding a lock for
     * minutes, unlike the small daily roll-up table {@see UsageDailyRepositoryInterface} prunes.
     *
     * @param \DateTimeInterface $cutoff
     * @return int Number of rows deleted
     */
    public function deleteOlderThan(\DateTimeInterface $cutoff): int;

    /**
     * Timestamp of the oldest recorded row, or null when the table is empty.
     *
     * This is what the daily roll-up (task 011) derives the raw/daily retention boundary from
     * instead of from configuration: a store that only started tracking last week has nothing
     * older to roll up, regardless of what a configured retention window says.
     *
     * @return \DateTimeImmutable|null
     */
    public function getOldestRecordedAt(): ?\DateTimeImmutable;

    /**
     * Every distinct consumer value present in the table, for the admin grid's filter source
     * (task 015).
     *
     * @return string[]
     */
    public function getDistinctConsumers(): array;
}

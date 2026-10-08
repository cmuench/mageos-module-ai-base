<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * How {@see \MageOS\AiBase\Api\UsageStatsInterface::getTimeSeries()} buckets a period.
 *
 * An enum rather than a string, per this codebase's convention for a fixed set of values: a caller
 * cannot pass a typo'd granularity that only fails once it reaches SQL.
 */
enum Granularity
{
    /**
     * One bucket per calendar day.
     */
    case Day;

    /**
     * One bucket per calendar month.
     */
    case Month;


    /**
     * The `date()` format that labels one bucket of this granularity.
     *
     * `Y-m-d` for a day, `Y-m` for a month, matching the `period` label
     * {@see UsageDailyReportInterface::seriesRange()} already returns, so a caller merging rows
     * from both tables never has to reconcile two label formats.
     *
     * @return string
     */
    public function toPeriodLabelFormat(): string
    {
        return match ($this) {
            self::Day => 'Y-m-d',
            self::Month => 'Y-m',
        };
    }

    /**
     * The step from one bucket boundary to the next.
     *
     * @return \DateInterval
     */
    public function toStepInterval(): \DateInterval
    {
        return new \DateInterval(match ($this) {
            self::Day => 'P1D',
            self::Month => 'P1M',
        });
    }
}

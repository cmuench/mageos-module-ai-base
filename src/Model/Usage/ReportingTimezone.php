<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * The one timezone usage reporting draws every calendar day, month and year in: the timezone
 * configured at Default Config (Stores > Configuration > General > Locale Options).
 *
 * {@see TimezoneInterface::getConfigTimezone()} called without a scope resolves store scope with a
 * null code, which means "whatever store is current". In the admin that is store 0, so it reads
 * Default Config; in cron and CLI the store resolver has already picked the default store view, so
 * it reads that store view's timezone instead. The roll-up runs from cron and the dashboard is
 * drawn in the admin, so without pinning the scope a store view with its own timezone would make
 * the roll-up bucket days in one timezone while the dashboard asks for periods in another, and the
 * daily rows would no longer line up with the periods they are read back for. Once those rows are
 * written under the wrong date they cannot be repaired, because the raw rows behind them are gone.
 *
 * Usage rows are also not per-store-view reports: a single dashboard total sums every store, so
 * there is no store-view timezone that would be right for all of it. One install-wide timezone,
 * pinned explicitly, is the only choice that gives every reader and writer the same days.
 *
 * Static rather than injected because {@see \MageOS\AiBase\Api\Data\Period}'s named constructors,
 * which are static value-object constructors taking a {@see TimezoneInterface}, need the very same
 * resolution; a pure function both they and the injected services call is what keeps it in one
 * place. Nothing here is state, so there is nothing a plugin should be able to intercept, which is
 * also why the static-function sniff is switched off for this file alone.
 */
// phpcs:disable Magento2.Functions.StaticFunction
class ReportingTimezone
{
    /**
     * The config scope the reporting timezone is read from: Default Config, never the ambient
     * store, for the reason the class docblock gives.
     */
    public const SCOPE_TYPE = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;

    /**
     * The Default Config timezone, read explicitly at default scope.
     *
     * The cast covers a {@see TimezoneInterface} implementation that hands back null for an unset
     * value; `\DateTimeZone` then rejects the empty name loudly instead of silently picking UTC.
     *
     * @param TimezoneInterface $timezone
     * @return \DateTimeZone
     */
    public static function resolve(TimezoneInterface $timezone): \DateTimeZone
    {
        return new \DateTimeZone((string) $timezone->getConfigTimezone(self::SCOPE_TYPE));
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

/**
 * The additive merge the real `mageos_ai_usage_daily` upsert performs, shared by the in-memory
 * daily fakes so they all mirror it the same way: never-null counts are summed, a nullable count
 * is summed when both sides have one, kept from whichever side has one otherwise, and stays null
 * only when neither does. Every other column takes the incoming row's value.
 */
final class DailyRowAddition
{
    private const ADDITIVE_COLUMNS = ['calls', 'failed_calls', 'input_tokens', 'output_tokens', 'total_tokens'];

    private const NULLABLE_ADDITIVE_COLUMNS = ['cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens'];

    /**
     * @param array<string,int|string|null> $stored
     * @param array<string,int|string|null> $incoming
     * @return array<string,int|string|null>
     */
    public static function add(array $stored, array $incoming): array
    {
        $merged = array_merge($stored, $incoming);
        foreach (self::ADDITIVE_COLUMNS as $column) {
            if (array_key_exists($column, $stored) || array_key_exists($column, $incoming)) {
                $merged[$column] = (int) ($stored[$column] ?? 0) + (int) ($incoming[$column] ?? 0);
            }
        }
        foreach (self::NULLABLE_ADDITIVE_COLUMNS as $column) {
            $storedValue = $stored[$column] ?? null;
            $incomingValue = $incoming[$column] ?? null;
            if ($storedValue === null && $incomingValue === null) {
                continue;
            }
            $merged[$column] = (int) $storedValue + (int) $incomingValue;
        }

        return $merged;
    }
}

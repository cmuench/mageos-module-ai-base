<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\AiBase\Model\Usage\ServiceRowLabels;

/**
 * Option source for the usage listing's service filter (task 015).
 *
 * The stored column it filters, `service_id`, is
 * {@see \MageOS\AiBase\Api\Data\AiServiceInterface::getId()}: an opaque JSON object key that
 * means nothing to an administrator reading the filter list. Each option is labelled through
 * {@see ServiceRowLabels} instead, the same labels the dashboard's charts use: the row's own name,
 * else its provider name, with the id appended when two rows would otherwise read the same. Two
 * Anthropic rows called "Chat AI" and "Summaries" are therefore two distinguishable options rather
 * than two identical "Anthropic" entries.
 *
 * Lists the currently configured rows rather than a distinct query over the usage table: the row
 * a historical call was served through is still the row an administrator wants to find it by, and
 * this is the same source the admin form itself reads its rows from.
 */
class ServiceRow implements OptionSourceInterface
{
    /**
     * @param ServiceRowLabels $serviceRowLabels Unique human label per configured row
     */
    public function __construct(
        private readonly ServiceRowLabels $serviceRowLabels,
    ) {
    }

    /**
     * @inheritdoc
     *
     * @return array<array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $labels = $this->serviceRowLabels->getConfiguredLabels();

        return array_map(
            fn (int|string $serviceId, string $label): array => [
                'value' => (string) $serviceId,
                'label' => $label,
            ],
            array_keys($labels),
            $labels,
        );
    }
}

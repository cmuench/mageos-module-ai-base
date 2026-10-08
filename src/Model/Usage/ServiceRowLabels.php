<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\ServiceRegistry;

/**
 * Turns the opaque `service_id` a usage row carries into a name an administrator recognises, the
 * same way everywhere usage is shown: the dashboard's charts and the usage grid's service filter.
 *
 * `service_id` is the JSON object key the admin form generated for a configured row, which names
 * nothing. The provider name alone is not enough either: the same backend is routinely configured
 * twice for different purposes ("Chat AI" and "Summaries", both Anthropic), and labelling both
 * "Anthropic" made them indistinguishable, and made the trend chart, which keys its lines by
 * label, silently drop one of them. So a row is labelled by the name the administrator gave it,
 * like {@see \MageOS\AiBase\Model\Config\Source\ConfiguredService} does, then by its provider
 * name, then by its raw code. An id that no longer belongs to a configured row keeps its raw id,
 * so its historical usage stays visible rather than vanishing.
 *
 * Labels that still collide (two unnamed rows of the same provider, two rows given the same name)
 * get their id appended, every one of them, so each label is unique and each says which row it is.
 */
class ServiceRowLabels
{
    /**
     * @param AiServiceSelectorInterface $serviceSelector Currently configured service rows
     * @param ServiceRegistry $serviceRegistry Registered backends, for the provider name fallback
     */
    public function __construct(
        private readonly AiServiceSelectorInterface $serviceSelector,
        private readonly ServiceRegistry $serviceRegistry,
    ) {
    }

    /**
     * A unique label per currently configured row, keyed by row id, in configuration order.
     *
     * PHP turns a numeric row id into an int key, which is why the key type is not narrowed to
     * string; look labels up by the id as stored.
     *
     * @return array<array-key,string>
     */
    public function getConfiguredLabels(): array
    {
        return $this->getLabels([]);
    }

    /**
     * A unique label per configured row and per extra id, keyed by id.
     *
     * The extra ids are the ones a chart is about to draw; any of them that is no longer a
     * configured row is labelled by its raw id. They are made unique together with the configured
     * rows, so a raw id can never end up sharing a label with a configured row either.
     *
     * @param string[] $serviceIds
     * @return array<array-key,string>
     */
    public function getLabels(array $serviceIds): array
    {
        $baseLabels = $this->baseLabels();
        foreach ($serviceIds as $serviceId) {
            $baseLabels[$serviceId] ??= $serviceId;
        }

        return $this->disambiguated($baseLabels);
    }

    /**
     * The undisambiguated label of every configured row, keyed by row id.
     *
     * @return array<array-key,string>
     */
    private function baseLabels(): array
    {
        $labels = [];
        foreach ($this->serviceSelector->getAll() as $service) {
            $labels[$service->getId()] = $this->baseLabel($service);
        }

        return $labels;
    }

    /**
     * The administrator's name for a row, else its provider name, else its raw code.
     *
     * The raw code is the last resort because a row outlives the module that registered its
     * provider, and should still be recognisable rather than disappear.
     *
     * @param AiServiceInterface $service
     * @return string
     */
    private function baseLabel(AiServiceInterface $service): string
    {
        return $service->getLabel()
            ?? $this->serviceRegistry->get($service->getCode())?->getName()
            ?? $service->getCode();
    }

    /**
     * Appends the id to every label more than one id shares.
     *
     * Every colliding label is suffixed, not only the second one, so neither row reads as "the
     * real one" and the reader can match each line or option to the row it belongs to.
     *
     * @param array<array-key,string> $labels
     * @return array<array-key,string>
     */
    private function disambiguated(array $labels): array
    {
        $occurrences = array_count_values($labels);
        $unique = [];
        foreach ($labels as $serviceId => $label) {
            $unique[$serviceId] = $occurrences[$label] > 1
                ? sprintf('%s (%s)', $label, $serviceId)
                : $label;
        }

        return $unique;
    }
}

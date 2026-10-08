<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\ModelList;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\Config\ConfigScope;

/**
 * Persists refreshed model lists per configured row in core_config_data.
 *
 * The payload is a JSON object `{"fetched_at": <unix ts>, "models": {value: label, ...}}` stored
 * at `mageos_ai/services/row_models/<row key>`, so a manually refreshed list survives across
 * requests and feeds that row's model field until the next refresh.
 *
 * Keyed on the row rather than the provider code because the list is a property of the endpoint a
 * row points at, not of the provider: two Ollama or LM Studio rows on different hosts serve
 * different models, and keyed per code each refresh overwrote the other row's list. Written at the
 * scope the row was refreshed in, so a website that overrides the services (and so carries copies
 * of default's row ids, pointed somewhere else) keeps its own list, while a website that inherits
 * reads default's through ordinary config inheritance.
 *
 * Lists written before this change live at `mageos_ai/services/models/<code>` (default scope), and
 * are still read as a fallback so an install that refreshed before upgrading keeps its suggestions.
 */
class Storage
{
    /**
     * Config path prefix of per-row lists; the row key is appended as the last path segment.
     */
    private const CONFIG_PATH_ROW_PREFIX = 'mageos_ai/services/row_models/';

    /**
     * Config path prefix of the per-code lists written before lists became per row; read only.
     */
    private const CONFIG_PATH_LEGACY_CODE_PREFIX = 'mageos_ai/services/models/';

    /**
     * A row id that can stand as a config path segment as it is: what the admin form generates.
     */
    private const SAFE_ROW_KEY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * Payload key holding the value => label model map.
     */
    private const KEY_MODELS = 'models';

    /**
     * Payload key holding the unix timestamp of the fetch.
     */
    private const KEY_FETCHED_AT = 'fetched_at';

    /**
     * @param WriterInterface $configWriter
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $jsonSerializer
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $jsonSerializer,
        private readonly TypeListInterface $cacheTypeList,
    ) {
    }

    /**
     * Persist a fetched model list for one configured row, at the scope it was refreshed in.
     *
     * @param string $rowId The row's id (`AiServiceInterface::getId()`)
     * @param array<string,string> $models Map of model value => label
     * @param ConfigScope $scope
     * @return void
     */
    public function saveForRow(string $rowId, array $models, ConfigScope $scope): void
    {
        // SerializerInterface still declares the string|bool return of the pre-exception days;
        // Json::serialize throws instead of returning false, so the cast only narrows the type.
        $payload = (string) $this->jsonSerializer->serialize([
            self::KEY_FETCHED_AT => time(),
            self::KEY_MODELS => $models,
        ]);
        $this->configWriter->save($this->rowPath($rowId), $payload, $scope->getType(), $scope->getId());
        // The writer bypasses the config cache; clean it so the next page load sees the new list.
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }

    /**
     * Load the stored model list of one configured row, as seen from the given scope.
     *
     * @param string $rowId
     * @param ConfigScope $scope
     * @return array<string,string>|null Map of model value => label, or null when nothing is stored
     */
    public function getModelsForRow(string $rowId, ConfigScope $scope): ?array
    {
        return $this->validatedModels(
            $this->scopeConfig->getValue($this->rowPath($rowId), $scope->getType(), $scope->getCode())
        );
    }

    /**
     * Load the list stored per provider code by versions before lists became per row.
     *
     * Kept as a read-only fallback: nothing writes this path any more, but an install that refreshed
     * a list before upgrading would otherwise lose its suggestions until each row is refreshed again.
     *
     * @param string $serviceCode
     * @return array<string,string>|null Map of model value => label, or null when nothing is stored
     */
    public function getLegacyModels(string $serviceCode): ?array
    {
        return $this->validatedModels(
            $this->scopeConfig->getValue(self::CONFIG_PATH_LEGACY_CODE_PREFIX . $serviceCode)
        );
    }

    /**
     * Config path of one row's list.
     *
     * Row ids are POST array keys and reach this class unnormalised. The ones the admin form
     * generates (`_<timestamp>_<ms>`) are used as they are, so the stored row stays recognisable in
     * `core_config_data`; anything else is hashed, because a `/` in it would nest the path and the
     * reader would hand back an array where a payload belongs.
     *
     * @param string $rowId
     * @return string
     */
    private function rowPath(string $rowId): string
    {
        $key = preg_match(self::SAFE_ROW_KEY_PATTERN, $rowId) === 1 ? $rowId : sha1($rowId);

        return self::CONFIG_PATH_ROW_PREFIX . $key;
    }

    /**
     * The model map of a stored payload, with every entry validated.
     *
     * Entries are validated on the way out rather than trusted: the payload is read back from
     * `core_config_data`, where a hand-edited row or a list written by an older version of this
     * module can hold anything, and the admin form renders these straight into option labels.
     *
     * @param mixed $raw
     * @return array<string,string>|null
     */
    private function validatedModels(mixed $raw): ?array
    {
        $models = $this->decode($raw)[self::KEY_MODELS] ?? null;
        if (!is_array($models)) {
            return null;
        }

        $validated = [];
        foreach ($models as $value => $label) {
            if (is_string($label)) {
                $validated[(string) $value] = $label;
            }
        }

        return $validated;
    }

    /**
     * Defensively decode a stored payload.
     *
     * @param mixed $raw
     * @return array<array-key,mixed>|null
     */
    private function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = $this->jsonSerializer->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

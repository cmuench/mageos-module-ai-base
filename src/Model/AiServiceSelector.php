<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;

class AiServiceSelector implements AiServiceSelectorInterface
{
    /**
     * Where the admin form stores the configured rows; also the path the form checks for a
     * deployment-configuration lock.
     */
    public const CONFIG_PATH_AI_SERVICES = 'mageos_ai/services/configuration';

    /**
     * Raw stored value the memoized services were parsed from.
     *
     * @var string|null
     */
    private ?string $parsedRaw = null;

    /**
     * Services parsed from $parsedRaw.
     *
     * @var list<AiServiceInterface>
     */
    private array $parsedServices = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param AiServiceInterfaceFactory $aiServiceFactory
     * @param SensitiveDataProcessor $sensitiveDataProcessor
     * @param ServiceScope $serviceScope Set by admin actions that act on the scope being edited
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AiServiceInterfaceFactory $aiServiceFactory,
        private readonly SensitiveDataProcessor $sensitiveDataProcessor,
        private readonly ServiceScope $serviceScope,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getAll(): array
    {
        return $this->getParsedConfig();
    }

    /**
     * @inheritdoc
     */
    public function getByCode(string $code): array
    {
        return array_values(array_filter(
            $this->getParsedConfig(),
            fn (AiServiceInterface $service) => $service->getCode() === $code,
        ));
    }

    /**
     * @inheritdoc
     */
    public function getById(string $id): ?AiServiceInterface
    {
        foreach ($this->getParsedConfig() as $service) {
            if ($service->getId() === $id) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Read and defensively parse the stored services configuration.
     *
     * Disabled rows are left out: see the filter below for why that happens here.
     *
     * Parsing decrypts every credential of every configured row, which a tool loop resolving its
     * service once per iteration would otherwise pay for on every turn. The memo is keyed on the
     * raw stored value rather than simply held: reading it back is cheap, and a store switch
     * (emulation in cron or a transactional email) has to re-parse rather than serve another
     * scope's credentials. The same holds for an admin action switching {@see ServiceScope}.
     *
     * @return list<AiServiceInterface>
     */
    private function getParsedConfig(): array
    {
        $raw = $this->readStoredValue();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        if ($raw === $this->parsedRaw) {
            return $this->parsedServices;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $services = [];
        foreach ($decoded as $rowId => $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $code = array_key_first($row);
            $configuration = $row[$code];
            if (!is_string($code) || !is_array($configuration)) {
                continue;
            }
            $service = $this->aiServiceFactory->create([
                'id' => (string) $rowId,
                'code' => $code,
                'configuration' => $this->sensitiveDataProcessor->decryptRow($code, $configuration),
            ]);

            // A disabled row is dropped here rather than at each call site, because every way a
            // consumer reaches a configured service goes through this class. Filtering once is
            // what makes "disabled" mean the same thing to the option source, the client factory
            // and a module reading credentials to call a provider itself.
            if ($service->isEnabled()) {
                $services[] = $service;
            }
        }

        $this->parsedRaw = $raw;

        return $this->parsedServices = $services;
    }

    /**
     * The raw stored rows, at the scope an admin action established or else at ambient store scope.
     *
     * Ambient store scope is what the public interface promises every other caller, so it stays the
     * answer whenever nothing set a scope explicitly.
     *
     * @return mixed
     */
    private function readStoredValue(): mixed
    {
        $scope = $this->serviceScope->getCurrent();
        if ($scope === null) {
            return $this->scopeConfig->getValue(self::CONFIG_PATH_AI_SERVICES, ScopeInterface::SCOPE_STORE);
        }

        return $this->scopeConfig->getValue(self::CONFIG_PATH_AI_SERVICES, $scope->getType(), $scope->getCode());
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Config;

use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\ServiceImporterInterface;
use PHPUnit\Framework\TestCase;

/**
 * An import followed by a read in the same process, the way a data patch and the code after it run.
 *
 * The unit test stores into an in-memory table and reads through a config fake that cannot be
 * stale. Here the write lands in `core_config_data` and the read goes through Magento's config,
 * which keeps the default scope in memory once loaded: this is what shows the import makes its
 * row visible without a cache flush in between.
 */
final class ServiceImporterTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';
    private const LEGACY_KEY_PATH = 'mageos_ai_test/legacy/api_key';

    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    protected function tearDown(): void
    {
        $writer = $this->objectManager->get(WriterInterface::class);
        $writer->delete(self::CONFIG_PATH);
        $writer->delete(self::LEGACY_KEY_PATH);
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function test_an_imported_service_is_readable_through_the_selector_in_the_same_process(): void
    {
        $selector = $this->objectManager->get(AiServiceSelectorInterface::class);
        $selector->getAll();

        $rowId = $this->objectManager->get(ServiceImporterInterface::class)
            ->import('openai', ['api_key' => 'sk-integration-import', 'model' => 'gpt-4o'], 'Imported');

        $service = $selector->getById($rowId);
        self::assertNotNull($service);
        self::assertSame('sk-integration-import', $service->getConfiguration()['api_key']);
        self::assertSame('Imported', $service->getLabel());
    }

    public function test_an_obscure_value_from_another_module_is_imported_once(): void
    {
        $this->objectManager->get(WriterInterface::class)->save(
            self::LEGACY_KEY_PATH,
            $this->objectManager->get(EncryptorInterface::class)->encrypt('sk-integration-obscure'),
        );
        $this->objectManager->get(AppConfig::class)->clean();
        $importer = $this->objectManager->get(ServiceImporterInterface::class);

        $first = $importer->importFromConfig('openai', ['api_key' => self::LEGACY_KEY_PATH]);
        $second = $importer->importFromConfig('openai', ['api_key' => self::LEGACY_KEY_PATH]);

        self::assertNotNull($first);
        self::assertSame($first, $second);
        $service = $this->objectManager->get(AiServiceSelectorInterface::class)->getById($first);
        self::assertSame('sk-integration-obscure', $service?->getConfiguration()['api_key']);
    }
}

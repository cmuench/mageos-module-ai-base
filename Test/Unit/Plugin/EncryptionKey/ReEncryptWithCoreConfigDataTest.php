<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Plugin\EncryptionKey;

require_once __DIR__ . '/../../Stubs/RecordingLogger.php';
require_once __DIR__ . '/../../Stubs/InMemoryStoredServicesStorage.php';
require_once __DIR__ . '/../../Stubs/VersionedEncryptor.php';

use Magento\Config\Model\Data\ReEncryptorList\CoreConfigDataReEncryptor\Handler;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\Config\CredentialReEncryptor;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Plugin\EncryptionKey\ReEncryptWithCoreConfigData;
use MageOS\AiBase\Test\Unit\Stubs\InMemoryStoredServicesStorage;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use MageOS\AiBase\Test\Unit\Stubs\VersionedEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * `bin/magento encryption:data:re-encrypt`: by the time it runs, the process has read both keys
 * from env.php, so the shared encryptor is the one to re-encrypt with.
 */
final class ReEncryptWithCoreConfigDataTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Handler::class)) {
            self::markTestSkipped('The core_config_data re-encryptor needs Magento_EncryptionKey, which ships with Magento.');
        }
    }

    public function test_it_re_encrypts_the_credentials_and_passes_the_core_errors_through(): void
    {
        $storage = (new InMemoryStoredServicesStorage())->withValue(
            1,
            'default',
            0,
            (new Json())->serialize(['_a' => ['openai' => ['api_key' => '0:3:enc(sk-secret)']]])
        );
        $encryptor = new VersionedEncryptor([0, 1]);
        $coreErrors = ['an error the core handler reported'];
        $subject = new ReEncryptWithCoreConfigData(
            new CredentialReEncryptor(
                $storage,
                new SensitiveDataProcessor($encryptor, new ServiceRegistry([])),
                new Json(),
                new RecordingLogger(),
            ),
            $encryptor,
        );

        $result = $subject->afterReEncrypt(
            (new \ReflectionClass(Handler::class))->newInstanceWithoutConstructor(),
            $coreErrors
        );

        self::assertSame($coreErrors, $result);
        self::assertSame(
            ['_a' => ['openai' => ['api_key' => '1:3:enc(sk-secret)']]],
            (new Json())->unserialize((string) $storage->getValue(1))
        );
    }
}

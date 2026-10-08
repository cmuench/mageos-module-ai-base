<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Plugin\EncryptionKey;

require_once __DIR__ . '/../../Stubs/RecordingLogger.php';
require_once __DIR__ . '/../../Stubs/InMemoryStoredServicesStorage.php';
require_once __DIR__ . '/../../Stubs/VersionedEncryptor.php';

use Magento\EncryptionKey\Model\ResourceModel\Key\Change;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\Config\CredentialReEncryptor;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Plugin\EncryptionKey\ReEncryptAfterKeyChange;
use MageOS\AiBase\Test\Unit\Stubs\InMemoryStoredServicesStorage;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use MageOS\AiBase\Test\Unit\Stubs\VersionedEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * The admin "Manage Encryption Key" page: Change rotates the key on a private copy of the
 * encryptor, so the plugin has to rebuild that key set from the key Change returns.
 *
 * Uses Magento's real Encryptor, since what is under test is exactly how its key versions behave.
 */
final class ReEncryptAfterKeyChangeTest extends TestCase
{
    private const OLD_KEY = 'old0123456789abcdef0123456789abc';
    private const NEW_KEY = 'new0123456789abcdef0123456789abc';

    private InMemoryStoredServicesStorage $storage;
    private RecordingLogger $logger;
    private Encryptor $sharedEncryptor;

    protected function setUp(): void
    {
        if (!class_exists(Change::class)) {
            self::markTestSkipped('Magento_EncryptionKey is not available in this environment.');
        }

        $this->storage = new InMemoryStoredServicesStorage();
        $this->logger = new RecordingLogger();
        $this->sharedEncryptor = $this->createEncryptor(self::OLD_KEY);
    }

    public function test_it_re_encrypts_stored_credentials_with_the_key_change_returned(): void
    {
        $this->storage->withValue(1, 'default', 0, $this->encode($this->sharedEncryptor->encrypt('sk-secret')));

        $result = $this->createSubject($this->sharedEncryptor)
            ->afterChangeEncryptionKey($this->createChange(), self::NEW_KEY);

        $ciphertext = $this->storedApiKey();
        self::assertSame(self::NEW_KEY, $result);
        self::assertStringStartsWith('1:', $ciphertext, 'The credential is not under the new key.');
        self::assertSame(
            'sk-secret',
            $this->createEncryptor('retired0123456789abcdef012345678 ' . self::NEW_KEY)->decrypt($ciphertext),
            'The credential still needs the old key to decrypt.'
        );
    }

    /**
     * The shared encryptor serves the rest of the request; adding the new key to it as a side
     * effect would change what everything else encrypts with.
     */
    public function test_it_leaves_the_shared_encryptor_on_its_own_keys(): void
    {
        $this->storage->withValue(1, 'default', 0, $this->encode($this->sharedEncryptor->encrypt('sk-secret')));

        $this->createSubject($this->sharedEncryptor)->afterChangeEncryptionKey($this->createChange(), self::NEW_KEY);

        self::assertStringStartsWith('0:', $this->sharedEncryptor->encrypt('anything'));
    }

    public function test_it_logs_and_writes_nothing_when_no_key_came_back(): void
    {
        $stored = $this->encode($this->sharedEncryptor->encrypt('sk-secret'));
        $this->storage->withValue(1, 'default', 0, $stored);

        $result = $this->createSubject($this->sharedEncryptor)->afterChangeEncryptionKey($this->createChange(), null);

        self::assertNull($result);
        self::assertSame($stored, $this->storage->getValue(1));
        self::assertSame('error', $this->logger->getRecords()[0]['level']);
    }

    /**
     * Only Magento's Encryptor can take a key at runtime. A replacement encryptor is not guessed at:
     * the credentials stay under the old key, which still works, and the log says what to do.
     */
    public function test_it_logs_and_writes_nothing_when_the_encryptor_cannot_take_a_new_key(): void
    {
        $stored = $this->encode('0:3:enc(sk-secret)');
        $this->storage->withValue(1, 'default', 0, $stored);

        $this->createSubject(new VersionedEncryptor([0]))->afterChangeEncryptionKey($this->createChange(), self::NEW_KEY);

        self::assertSame($stored, $this->storage->getValue(1));
        self::assertSame('error', $this->logger->getRecords()[0]['level']);
    }

    private function createSubject(\Magento\Framework\Encryption\EncryptorInterface $encryptor): ReEncryptAfterKeyChange
    {
        return new ReEncryptAfterKeyChange(
            new CredentialReEncryptor(
                $this->storage,
                new SensitiveDataProcessor($encryptor, new ServiceRegistry([])),
                new Json(),
                $this->logger,
            ),
            $encryptor,
            $this->logger,
        );
    }

    /**
     * Change is a resource model with a database context behind it; the plugin never touches it, so
     * an instance without its constructor is enough to stand in as the subject.
     */
    private function createChange(): Change
    {
        return (new \ReflectionClass(Change::class))->newInstanceWithoutConstructor();
    }

    /**
     * Magento's Encryptor over a deployment config that holds only the given `crypt/key`.
     */
    private function createEncryptor(string $cryptKey): Encryptor
    {
        $deploymentConfig = new class ($cryptKey) extends DeploymentConfig {
            public function __construct(private readonly string $cryptKey)
            {
            }

            public function get($key = null, $defaultValue = null)
            {
                return $key === Encryptor::PARAM_CRYPT_KEY ? $this->cryptKey : $defaultValue;
            }
        };

        return new Encryptor(new Random(), $deploymentConfig, new KeyValidator());
    }

    private function encode(string $apiKey): string
    {
        return (new Json())->serialize(['_a' => ['openai' => ['api_key' => $apiKey, 'model' => 'gpt-4o']]]);
    }

    private function storedApiKey(): string
    {
        return (new Json())->unserialize((string) $this->storage->getValue(1))['_a']['openai']['api_key'];
    }
}

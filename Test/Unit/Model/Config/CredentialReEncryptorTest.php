<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config;

require_once __DIR__ . '/../../Stubs/RecordingLogger.php';
require_once __DIR__ . '/../../Stubs/InMemoryStoredServicesStorage.php';
require_once __DIR__ . '/../../Stubs/VersionedEncryptor.php';

use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Model\Config\CredentialReEncryptor;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use MageOS\AiBase\Model\FieldDescriptor;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Test\Unit\Stubs\InMemoryStoredServicesStorage;
use MageOS\AiBase\Test\Unit\Stubs\RecordingLogger;
use MageOS\AiBase\Test\Unit\Stubs\VersionedEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * Re-encrypting the AI credentials after an encryption key change.
 *
 * The encryptor holds key versions 0 (old) and 1 (new) unless a test says otherwise, which is the
 * state right after a rotation: everything stored is still under 0 and must end up under 1.
 */
final class CredentialReEncryptorTest extends TestCase
{
    private InMemoryStoredServicesStorage $storage;
    private RecordingLogger $logger;
    private CredentialReEncryptor $subject;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStoredServicesStorage();
        $this->logger = new RecordingLogger();
        $this->subject = new CredentialReEncryptor(
            $this->storage,
            new SensitiveDataProcessor(new VersionedEncryptor([0]), new ServiceRegistry([$this->createFakeService()])),
            new Json(),
            $this->logger,
        );
    }

    /**
     * Each scope stores its own copy of the rows, with its own ciphertexts; a website override left
     * under the old key would stop working on that website the moment the old key is removed.
     */
    public function test_it_re_encrypts_the_credentials_of_every_scope_under_the_newest_key(): void
    {
        $this->storage
            ->withValue(1, 'default', 0, $this->encode(['_a' => ['openai' => ['api_key' => '0:3:enc(sk-default)', 'model' => 'gpt-4o']]]))
            ->withValue(2, 'websites', 1, $this->encode(['_b' => ['openai' => ['api_key' => '0:3:enc(sk-website)', 'model' => 'gpt-4o']]]));

        $rewritten = $this->subject->reEncrypt(new VersionedEncryptor([0, 1]));

        self::assertSame(2, $rewritten);
        self::assertSame(
            ['_a' => ['openai' => ['api_key' => '1:3:enc(sk-default)', 'model' => 'gpt-4o']]],
            $this->decode(1)
        );
        self::assertSame(
            ['_b' => ['openai' => ['api_key' => '1:3:enc(sk-website)', 'model' => 'gpt-4o']]],
            $this->decode(2)
        );
        self::assertSame(1, $this->storage->getCacheInvalidations());
    }

    /**
     * Whether a field is a credential comes from the provider's descriptors, the same as when it was
     * encrypted, so a credential under an unusual name is re-encrypted and a setting that merely
     * looks like a ciphertext is not decrypted into nothing.
     */
    public function test_it_follows_the_provider_schema_for_which_fields_to_re_encrypt(): void
    {
        $this->storage->withValue(1, 'default', 0, $this->encode([
            '_a' => ['fakeai' => ['certificate' => '0:3:enc(cert)', 'token' => '0:3:enc(public)']],
        ]));

        $this->subject->reEncrypt(new VersionedEncryptor([0, 1]));

        self::assertSame(
            ['_a' => ['fakeai' => ['certificate' => '1:3:enc(cert)', 'token' => '0:3:enc(public)']]],
            $this->decode(1)
        );
    }

    /**
     * A value whose key is no longer configured decrypts to an empty string. Re-encrypting that
     * would store an encrypted empty string and lose the credential for good; left as it is, it
     * comes back as soon as the key is restored.
     */
    public function test_it_leaves_a_credential_it_cannot_decrypt_as_stored_and_logs_where_it_is(): void
    {
        $this->storage->withValue(7, 'stores', 3, $this->encode([
            '_a' => ['openai' => ['api_key' => '5:3:enc(sk-lost-key)']],
            '_b' => ['anthropic' => ['api_key' => '0:3:enc(sk-ant)']],
        ]));

        $this->subject->reEncrypt(new VersionedEncryptor([0, 1]));

        self::assertSame('5:3:enc(sk-lost-key)', $this->decode(7)['_a']['openai']['api_key']);
        self::assertSame('1:3:enc(sk-ant)', $this->decode(7)['_b']['anthropic']['api_key']);
        $record = $this->logger->getRecords()[0];
        self::assertSame('error', $record['level']);
        self::assertSame(
            ['config_id' => 7, 'scope' => 'stores', 'scope_id' => 3, 'row_id' => '_a', 'service_code' => 'openai', 'field' => 'api_key'],
            $record['context']
        );
        self::assertStringNotContainsString('sk-lost-key', $this->logger->getMessages());
    }

    public function test_it_leaves_a_credential_as_stored_when_decrypting_throws(): void
    {
        $stored = $this->encode(['_a' => ['openai' => ['api_key' => '0:3:enc(sk)']]]);
        $this->storage->withValue(1, 'default', 0, $stored);

        $rewritten = $this->subject->reEncrypt((new VersionedEncryptor([0, 1]))->givenDecryptThrows());

        self::assertSame(0, $rewritten);
        self::assertSame($stored, $this->storage->getValue(1));
        self::assertCount(1, $this->logger->getRecords());
    }

    /**
     * The new ciphertext is checked before it replaces a working one, so an encryptor that writes
     * something it cannot read back cannot destroy the credential.
     */
    public function test_it_keeps_the_stored_credential_when_the_new_ciphertext_does_not_decrypt_back(): void
    {
        $stored = $this->encode(['_a' => ['openai' => ['api_key' => '0:3:enc(sk)']]]);
        $this->storage->withValue(1, 'default', 0, $stored);

        $this->subject->reEncrypt((new VersionedEncryptor([0, 1]))->givenEncryptionCorrupts());

        self::assertSame($stored, $this->storage->getValue(1));
    }

    /**
     * An administrator saving the form while the re-encryption runs has already written the newest
     * state; writing the re-encrypted copy of what was read before would undo their save.
     */
    public function test_it_does_not_overwrite_a_value_that_changed_while_it_was_re_encrypted(): void
    {
        $concurrent = $this->encode(['_a' => ['openai' => ['api_key' => '1:3:enc(sk-typed-just-now)']]]);
        $this->storage
            ->withValue(1, 'default', 0, $this->encode(['_a' => ['openai' => ['api_key' => '0:3:enc(sk)']]]))
            ->givenChangedOnWrite(1, $concurrent);

        $rewritten = $this->subject->reEncrypt(new VersionedEncryptor([0, 1]));

        self::assertSame(0, $rewritten);
        self::assertSame($concurrent, $this->storage->getValue(1));
        self::assertSame('warning', $this->logger->getRecords()[0]['level']);
    }

    public function test_it_leaves_a_value_that_is_not_json_alone(): void
    {
        $this->storage->withValue(1, 'default', 0, '{not json');

        $rewritten = $this->subject->reEncrypt(new VersionedEncryptor([0, 1]));

        self::assertSame(0, $rewritten);
        self::assertSame('{not json', $this->storage->getValue(1));
        self::assertSame('error', $this->logger->getRecords()[0]['level']);
    }

    /**
     * A legacy plaintext credential and an empty one have no ciphertext to re-encrypt; with nothing
     * changed, nothing is written and the config cache is left alone.
     */
    public function test_it_writes_nothing_when_there_is_no_ciphertext_to_re_encrypt(): void
    {
        $stored = $this->encode([
            '_a' => ['openai' => ['api_key' => 'legacy-plaintext', 'model' => 'gpt-4o']],
            '_b' => ['anthropic' => ['api_key' => '']],
            '__empty' => '',
        ]);
        $this->storage->withValue(1, 'default', 0, $stored);

        $rewritten = $this->subject->reEncrypt(new VersionedEncryptor([0, 1]));

        self::assertSame(0, $rewritten);
        self::assertSame($stored, $this->storage->getValue(1));
        self::assertSame(0, $this->storage->getCacheInvalidations());
        self::assertSame([], $this->logger->getRecords());
    }

    /**
     * @param array<array-key,mixed> $rows
     * @return string
     */
    private function encode(array $rows): string
    {
        return (new Json())->serialize($rows);
    }

    /**
     * @param int $configId
     * @return array<array-key,mixed>
     */
    private function decode(int $configId): array
    {
        return (new Json())->unserialize((string) $this->storage->getValue($configId));
    }

    /**
     * A provider with a credential the name heuristic would miss ("certificate") and a setting it
     * would wrongly catch ("token").
     *
     * @return AiServiceConfigurationInterface
     */
    private function createFakeService(): AiServiceConfigurationInterface
    {
        return new class implements AiServiceConfigurationInterface {
            public function getCode(): string
            {
                return 'fakeai';
            }

            public function getName(): string
            {
                return 'Fake AI';
            }

            public function getConfigurationFields(): array
            {
                return [
                    new FieldDescriptor(
                        name: 'certificate',
                        label: 'Certificate',
                        type: FieldDescriptorInterface::TYPE_TEXT,
                        encrypted: true,
                    ),
                    new FieldDescriptor(name: 'token', label: 'Token', type: FieldDescriptorInterface::TYPE_TEXT),
                ];
            }

            public function getSupportedModels(): array
            {
                return [];
            }
        };
    }
}

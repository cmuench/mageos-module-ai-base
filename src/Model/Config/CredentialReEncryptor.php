<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Re-encrypts every stored AI credential with the newest encryption key.
 *
 * Magento's own re-encryption only knows values that are a ciphertext as a whole: config fields
 * whose backend model is exactly `Config\Model\Config\Backend\Encrypted`, or values that look like
 * `N:N:...`. This module's credentials sit inside the JSON value at
 * `mageos_ai/services/configuration`, so a key rotation leaves them under the old key, and once the
 * merchant removes that key from `crypt/key` (standard advice after a rotation) every AI key
 * decrypts to an empty string. The plugins in `Plugin\EncryptionKey` call this at the point where
 * Magento re-encrypts its own values.
 *
 * Built so that it cannot lose a credential:
 * - only the fields SensitiveDataProcessor reports as encrypted are touched, everything else in the
 *   JSON is written back as it was read;
 * - a value that does not decrypt, or whose new ciphertext does not decrypt back to the same
 *   plaintext, is left exactly as stored and logged, never blanked;
 * - a stored value is only replaced while it still holds what was read, so a concurrent admin save
 *   is never undone;
 * - each scope's copy is handled on its own.
 *
 * The encryptor is passed in rather than injected because the flows differ: the admin page rotates
 * the key on a private copy of the encryptor that the shared instance never sees, while the
 * re-encrypt command runs in a process that read both keys from env.php at startup.
 */
class CredentialReEncryptor
{
    /**
     * @param StoredServicesStorageInterface $storage Every scope's raw stored services value
     * @param SensitiveDataProcessor $sensitiveDataProcessor Decides which fields hold an encrypted credential
     * @param Json $json The serializer the config backend model stores the rows with
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoredServicesStorageInterface $storage,
        private readonly SensitiveDataProcessor $sensitiveDataProcessor,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Re-encrypt every stored credential, at every scope, with the encryptor's current key.
     *
     * The encryptor reads the key version off each ciphertext to decrypt it, so it must still hold
     * the key a value was written with, and encrypts with its newest key.
     *
     * @param EncryptorInterface $encryptor
     * @return int Number of stored values that were rewritten
     */
    public function reEncrypt(EncryptorInterface $encryptor): int
    {
        $rewritten = count(array_filter(
            $this->storage->getAll(),
            fn (StoredServicesValue $stored): bool => $this->reEncryptStoredValue($stored, $encryptor),
        ));

        if ($rewritten > 0) {
            $this->storage->invalidateCache();
        }

        return $rewritten;
    }

    /**
     * Re-encrypt the credentials in one stored copy and write it back if anything changed.
     *
     * @param StoredServicesValue $stored
     * @param EncryptorInterface $encryptor
     * @return bool Whether the stored value was rewritten
     */
    private function reEncryptStoredValue(StoredServicesValue $stored, EncryptorInterface $encryptor): bool
    {
        $rows = $this->decode($stored);
        if ($rows === null) {
            return false;
        }

        $reEncrypted = $this->reEncryptRows($rows, $stored, $encryptor);
        if ($reEncrypted === $rows) {
            return false;
        }

        $serialized = $this->json->serialize($reEncrypted);
        if (is_string($serialized) && $this->storage->replace($stored, $serialized)) {
            return true;
        }

        $this->logger->warning(
            'AI service credentials were not re-encrypted because the stored value changed while they '
            . 'were being processed. Run the re-encryption again.',
            $this->describe($stored),
        );

        return false;
    }

    /**
     * The stored rows, or null when the value is not the JSON object the backend model writes.
     *
     * Something unreadable is left alone rather than rewritten, since rewriting it would mean
     * replacing data this class does not understand.
     *
     * @param StoredServicesValue $stored
     * @return array<array-key,mixed>|null
     */
    private function decode(StoredServicesValue $stored): ?array
    {
        try {
            $rows = $this->json->unserialize($stored->value);
        } catch (\InvalidArgumentException) {
            $rows = null;
        }

        if (is_array($rows)) {
            return $rows;
        }

        $this->logger->error(
            'Stored AI service configuration is not valid JSON, so its credentials were not re-encrypted.',
            $this->describe($stored),
        );

        return null;
    }

    /**
     * Re-encrypt every row in a stored copy, leaving anything that is not a service row as it is.
     *
     * @param array<array-key,mixed> $rows Shape: [rowId => [serviceCode => [field => value]]]
     * @param StoredServicesValue $stored
     * @param EncryptorInterface $encryptor
     * @return array<array-key,mixed>
     */
    private function reEncryptRows(array $rows, StoredServicesValue $stored, EncryptorInterface $encryptor): array
    {
        foreach ($rows as $rowId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $serviceCode = array_key_first($row);
            if ($serviceCode === null || !is_array($row[$serviceCode])) {
                continue;
            }
            $row[$serviceCode] = $this->reEncryptFields(
                (string) $serviceCode,
                $row[$serviceCode],
                $this->describe($stored) + ['row_id' => (string) $rowId, 'service_code' => (string) $serviceCode],
                $encryptor,
            );
            $rows[$rowId] = $row;
        }

        return $rows;
    }

    /**
     * Re-encrypt the encrypted credential fields of one service row.
     *
     * @param string $serviceCode
     * @param array<array-key,mixed> $configuration
     * @param array<string,int|string> $context Where the row is stored, for the log
     * @param EncryptorInterface $encryptor
     * @return array<array-key,mixed>
     */
    private function reEncryptFields(
        string $serviceCode,
        array $configuration,
        array $context,
        EncryptorInterface $encryptor
    ): array {
        foreach ($this->sensitiveDataProcessor->getEncryptedFieldNames($serviceCode, $configuration) as $field) {
            $ciphertext = $configuration[$field];
            $reEncrypted = is_string($ciphertext) ? $this->reEncryptValue($ciphertext, $encryptor) : null;
            if ($reEncrypted === null) {
                $this->logger->error(
                    'An AI service credential could not be decrypted, so it was left as stored instead of '
                    . 're-encrypted. It is still under a previous encryption key: keep that key in '
                    . 'crypt/key, or enter the credential again in the AI configuration.',
                    $context + ['field' => $field],
                );
                continue;
            }
            $configuration[$field] = $reEncrypted;
        }

        return $configuration;
    }

    /**
     * A ciphertext under the newest key, or null when it cannot safely be produced.
     *
     * Magento's encryptor reports a failed decrypt as an empty string (a missing key version, a
     * wrong key) and an unsupported cipher version as a bare \Exception. Either way the value is
     * kept as stored. The new ciphertext is also decrypted once before it is used, so a
     * misbehaving encryptor cannot replace a working credential with one that does not decrypt.
     *
     * @param string $ciphertext
     * @param EncryptorInterface $encryptor
     * @return string|null
     */
    private function reEncryptValue(string $ciphertext, EncryptorInterface $encryptor): ?string
    {
        try {
            $plaintext = (string) $encryptor->decrypt($ciphertext);
            if ($plaintext === '') {
                return null;
            }
            $reEncrypted = (string) $encryptor->encrypt($plaintext);

            return (string) $encryptor->decrypt($reEncrypted) === $plaintext ? $reEncrypted : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Where a stored copy lives, for the log; never its content.
     *
     * @param StoredServicesValue $stored
     * @return array<string,int|string>
     */
    private function describe(StoredServicesValue $stored): array
    {
        return ['config_id' => $stored->configId, 'scope' => $stored->scope, 'scope_id' => $stored->scopeId];
    }
}

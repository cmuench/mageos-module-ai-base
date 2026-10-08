<?php

declare(strict_types=1);

namespace MageOS\AiBase\Plugin\EncryptionKey;

use Magento\Config\Model\Data\ReEncryptorList\CoreConfigDataReEncryptor\Handler;
use Magento\Framework\Encryption\EncryptorInterface;
use MageOS\AiBase\Model\Config\CredentialReEncryptor;

/**
 * Re-encrypts AI credentials when Magento re-encrypts `core_config_data` after a key change.
 *
 * From Magento 2.4.7-p4 a key change is two steps: `bin/magento encryption:key:change` only adds
 * the new key to env.php, and `bin/magento encryption:data:re-encrypt` runs the registered
 * re-encryptors. The `core_config_data` one only picks up values that are a ciphertext as a whole
 * (`N:N:...`); the services value is a JSON object with ciphertexts inside, so it is skipped and
 * the AI credentials stay under the old key.
 *
 * A plugin on that handler rather than a re-encryptor of its own: a re-encryptor has to implement
 * Magento_EncryptionKey's HandlerInterface, which does not exist before 2.4.7-p4, and a class
 * implementing a missing interface breaks `setup:di:compile` on those versions. A plugin on a class
 * that does not exist, or whose module is disabled, is simply never called. It also means running
 * the `core_config_data` re-encryptor alone covers these credentials, which are core config data.
 *
 * The command runs in a process that read env.php after the key change, so the shared encryptor
 * already holds the old keys and the new one; nothing has to be rotated here. Failures are logged
 * rather than added to the command's error list, since building its Error objects would need a
 * factory that only exists from 2.4.7-p4 on.
 */
class ReEncryptWithCoreConfigData
{
    /**
     * @param CredentialReEncryptor $reEncryptor
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly CredentialReEncryptor $reEncryptor,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    /**
     * Re-encrypt the AI credentials once Magento has re-encrypted its own config values.
     *
     * @param Handler $subject
     * @param array<mixed> $result Row level errors from the core handler, passed through untouched
     * @return array<mixed>
     */
    public function afterReEncrypt(Handler $subject, array $result): array
    {
        $this->reEncryptor->reEncrypt($this->encryptor);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Plugin\EncryptionKey;

use Magento\EncryptionKey\Model\ResourceModel\Key\Change;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use MageOS\AiBase\Model\Config\CredentialReEncryptor;
use Psr\Log\LoggerInterface;

/**
 * Re-encrypts AI credentials when the key is changed on the admin "Manage Encryption Key" page.
 *
 * That page (Magento 2.4.7 up to the 2.4.7 patch releases; removed in 2.4.8) calls
 * Change::changeEncryptionKey(), which re-encrypts only config fields whose backend model is
 * exactly `Config\Model\Config\Backend\Encrypted`, and stored card numbers. The AI credentials live
 * inside a JSON value with its own backend model, so without this they stay under the old key.
 *
 * Change rotates the key on a private clone of the encryptor, so the shared instance injected here
 * still holds only the old keys. This builds the same key set again: a clone of the shared
 * encryptor with the key Change returned added as the newest. It runs after Change committed and
 * wrote the new key to env.php, so a failure here leaves every credential under the old key, which
 * is still configured and still decrypts; nothing is lost and the re-encryption can be repeated.
 *
 * Magento_EncryptionKey is not a dependency of this module: on an install where it is disabled,
 * nothing calls Change and this plugin never runs. From 2.4.8 the page is gone and the same work is
 * done by {@see ReEncryptWithCoreConfigData} during `bin/magento encryption:data:re-encrypt`.
 */
class ReEncryptAfterKeyChange
{
    /**
     * @param CredentialReEncryptor $reEncryptor
     * @param EncryptorInterface $encryptor The shared encryptor, still holding only the old keys
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CredentialReEncryptor $reEncryptor,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Re-encrypt the AI credentials with the key that was just added.
     *
     * @param Change $subject
     * @param mixed $result The new key, as Change::changeEncryptionKey() returns it
     * @return mixed
     */
    public function afterChangeEncryptionKey(Change $subject, mixed $result): mixed
    {
        $rotated = is_string($result) && $result !== '' ? $this->withNewKey($result) : null;
        if ($rotated === null) {
            $this->logger->error(
                'The encryption key was changed, but AI service credentials could not be re-encrypted '
                . 'because the new key was not available. Keep the previous key in crypt/key and run '
                . '`bin/magento encryption:data:re-encrypt core_config_data` where available, or enter '
                . 'the credentials again.'
            );

            return $result;
        }

        $this->reEncryptor->reEncrypt($rotated);

        return $result;
    }

    /**
     * A copy of the shared encryptor with the new key added as the newest, as Change holds it.
     *
     * Only Magento's own Encryptor can be given a key at runtime; Change itself relies on that same
     * method. A replacement encryptor without it gets null, and the failure is logged.
     *
     * @param string $key
     * @return EncryptorInterface|null
     */
    private function withNewKey(string $key): ?EncryptorInterface
    {
        if (!$this->encryptor instanceof Encryptor) {
            return null;
        }

        $rotated = clone $this->encryptor;
        $rotated->setNewKey($key);

        return $rotated;
    }
}

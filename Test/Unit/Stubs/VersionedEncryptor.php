<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\Encryption\EncryptorInterface;

/**
 * An {@see EncryptorInterface} that behaves like Magento's on the points key rotation depends on:
 * the key version is the first segment of a ciphertext, a value under a key it does not hold
 * decrypts to an empty string, and it encrypts with its newest key.
 *
 * Ciphertexts read `<version>:3:enc(<plaintext>)`, so a test can see which key a value is under.
 */
final class VersionedEncryptor implements EncryptorInterface
{
    private bool $isCorruptingEncryption = false;

    private bool $isThrowingOnDecrypt = false;

    /**
     * @param list<int> $keyVersions Key versions this encryptor holds; the highest is the newest
     */
    public function __construct(private readonly array $keyVersions)
    {
    }

    /**
     * Make encrypt() return a ciphertext that does not decrypt back to its plaintext.
     */
    public function givenEncryptionCorrupts(): self
    {
        $this->isCorruptingEncryption = true;

        return $this;
    }

    /**
     * Make decrypt() throw, the way Magento's does for an unsupported cipher version.
     */
    public function givenDecryptThrows(): self
    {
        $this->isThrowingOnDecrypt = true;

        return $this;
    }

    public function encrypt($data)
    {
        $ciphertext = max($this->keyVersions) . ':3:enc(' . $data . ')';

        return $this->isCorruptingEncryption ? $ciphertext . 'garbage' : $ciphertext;
    }

    public function decrypt($data)
    {
        if ($this->isThrowingOnDecrypt) {
            throw new \Exception('Not supported cipher version');
        }
        if (!preg_match('/^(\d+):3:enc\((.*)\)$/', (string) $data, $matches)) {
            return '';
        }

        return in_array((int) $matches[1], $this->keyVersions, true) ? $matches[2] : '';
    }

    public function getHash($password, $salt = false)
    {
        return hash('sha256', (string) $password);
    }

    public function hash($data)
    {
        return hash('sha256', (string) $data);
    }

    public function validateHash($password, $hash)
    {
        return $this->getHash($password) === $hash;
    }

    public function isValidHash($password, $hash)
    {
        return $this->validateHash($password, $hash);
    }

    public function validateHashVersion($hash, $validateCount = false)
    {
        return true;
    }

    public function validateKey($key)
    {
        return null;
    }
}

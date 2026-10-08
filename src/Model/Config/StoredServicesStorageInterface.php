<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

/**
 * Raw access to every stored copy of the services configuration, at every scope.
 *
 * Re-encryption after an encryption key change must not go through the config model: the backend
 * model masks credentials on load and restores them from the old value on save, and the config
 * cache may hold a stale copy. It reads and writes the stored JSON as it is. An interface so the
 * re-encryption logic can be tested against an in-memory fake instead of a database.
 *
 * Internal to this module, not part of its public API.
 */
interface StoredServicesStorageInterface
{
    /**
     * Every non-empty stored services value, at every scope.
     *
     * @return list<StoredServicesValue>
     */
    public function getAll(): array;

    /**
     * Replace a stored value, but only while it still holds what was read.
     *
     * Writing over a value that changed since it was read (an admin saving the form in the
     * meantime) would silently undo that save, so the write is conditional on the old value.
     *
     * @param StoredServicesValue $stored The value as it was read
     * @param string $replacement
     * @return bool Whether the value was replaced
     */
    public function replace(StoredServicesValue $stored, string $replacement): bool;

    /**
     * Drop cached configuration, so nothing keeps serving a ciphertext that was replaced.
     *
     * Values under the previous key keep decrypting only while that key is still configured; once
     * the merchant removes it, a cached copy would decrypt to an empty credential.
     *
     * @return void
     */
    public function invalidateCache(): void;
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Adds a configured AI service from code, for a module moving its own saved credentials here.
 *
 * Modules that predate this one keep their own API key fields. Asking every merchant to copy a key
 * by hand into the AI Configuration form on upgrade loses some of them along the way, and a key
 * cannot be read back out of an `obscure` field to copy it. A data patch in that module can call
 * this instead, store the row id it gets back in its own configuration, and stop reading its old
 * fields.
 *
 * The row it adds is an ordinary row: default scope, enabled, credentials encrypted exactly as the
 * admin form encrypts them, an id shaped like the ids the form generates. The administrator sees,
 * edits and deletes it like any other.
 *
 * Both methods are idempotent: a row of the same service already holding every imported value is
 * returned instead of added. A data patch that runs again, or two modules importing the same key,
 * end up with one row.
 *
 * Works without an admin session or a store: it writes the default scope directly and reinitialises
 * the configuration, so a read later in the same process (the next patch, a CLI command) sees it.
 *
 * Stable to call, not meant to be implemented: methods may be added in a minor release. To change
 * what it does, write a plugin on it instead of replacing it.
 *
 * @api
 */
interface ServiceImporterInterface
{
    /**
     * Add a service row at default scope from plain (decrypted) field values and return its row id.
     *
     * Only fields the provider declares in getConfigurationFields() are accepted, and an unknown
     * key is refused rather than dropped: a misspelt `apikey` would otherwise import a row without
     * its key, which nobody notices until a call fails in production. A field may be left out (an
     * import without a model is fine, the provider's default applies), and an empty value counts
     * as left out.
     *
     * @param string $serviceCode A registered service code, e.g. `openai`
     * @param array<string,string> $configuration Field name => plain value, e.g. ['api_key' => 'sk-...']
     * @param string|null $label The row name shown in the admin; null or empty leaves it unnamed
     * @return string The row id, to store in a `ConfiguredService` select and resolve with
     *         AiServiceSelectorInterface::getById() or AiClientFactoryInterface::createById()
     * @throws LocalizedException When the code is not registered, a field is not declared or not a
     *         string, the services are pinned in deployment configuration, or the stored services
     *         changed while the row was being added
     */
    public function import(string $serviceCode, array $configuration, ?string $label = null): string;

    /**
     * Add a service row from values another module stored in its own configuration.
     *
     * Each path is read at default scope through the regular configuration, so a value pinned in
     * `app/etc/env.php` or `config.php` is found as well as one saved in the admin. A field this
     * module stores encrypted is decrypted first when the stored value is a Magento ciphertext,
     * which is what an `obscure` field with the `Encrypted` backend model holds; any other value is
     * taken as it is, which covers a plaintext key saved before the field was encrypted.
     *
     * Returns null and adds nothing when there is nothing to import: every credential field the
     * provider declares is empty (or, for a provider without credentials such as Ollama, every
     * mapped value is). A data patch can therefore call it unconditionally.
     *
     * @param string $serviceCode A registered service code, e.g. `openai`
     * @param array<string,string> $fieldPaths This module's field name => the caller's config path,
     *        e.g. ['api_key' => 'my_module/general/api_key', 'model' => 'my_module/general/model']
     * @param string|null $label The row name shown in the admin; null or empty leaves it unnamed
     * @return string|null The row id, or null when there was nothing to import
     * @throws LocalizedException For the same reasons as import()
     */
    public function importFromConfig(string $serviceCode, array $fieldPaths, ?string $label = null): ?string;
}

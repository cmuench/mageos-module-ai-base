<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model;

use MageOS\AiBase\Model\Config\ConfigScope;

/**
 * Lets admin code read the configured services of one specific scope for the length of a callback.
 *
 * `AiServiceSelectorInterface` resolves at store scope in whatever scope is ambient, and in
 * adminhtml that is always the default scope. The admin form edits websites and store views too,
 * and its Test Connection and Refresh Models buttons, and the option source other modules put in
 * their own config fields, have to act on the rows of the scope on screen rather than on default's.
 *
 * Why an emulation and not a scope argument:
 * - The public selector interface is `@api` and takes no scope, and adding one would make every
 *   third-party implementation of it incomplete.
 * - Test Connection builds its client through `AiClientFactoryInterface`, which reads the row
 *   through the selector. Establishing the scope around that call keeps the button exercising the
 *   real client factory (including a third-party preference for it) instead of a parallel path
 *   that only this button uses.
 * - Magento's own store emulation cannot do this job: it switches the current store, so it has no
 *   way to express "website X" without dragging in one of its store views' overrides.
 *
 * The state lives on a shared instance for the same reason store emulation's does, and is only ever
 * set through {@see run()}, which restores the previous scope even when the callback throws.
 */
class ServiceScope
{
    /**
     * The scope established by the innermost running callback, or null for the ambient scope.
     *
     * @var ConfigScope|null
     */
    private ?ConfigScope $current = null;

    /**
     * Run a callback with the configured services read at the given scope.
     *
     * @template T
     * @param ConfigScope $scope
     * @param callable $callback
     * @phpstan-param callable(): T $callback
     * @return mixed The callback's return value
     * @phpstan-return T
     */
    public function run(ConfigScope $scope, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $scope;
        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    /**
     * The scope a running callback established, or null when the ambient scope applies.
     *
     * @return ConfigScope|null
     */
    public function getCurrent(): ?ConfigScope
    {
        return $this->current;
    }
}

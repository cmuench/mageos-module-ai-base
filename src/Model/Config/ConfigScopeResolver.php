<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Works out which configuration scope an admin request or a rendered config field is about.
 *
 * The config edit page addresses a scope with `website/<id>` or `store/<id>` in its URL, and
 * Magento's own config form reads exactly those two parameters, store first. Every admin surface
 * of this module that has to act on "the scope being edited" goes through here, so the form, the
 * Test Connection and Refresh Models actions and the option source cannot disagree about it.
 */
class ConfigScopeResolver
{
    /**
     * @param StoreManagerInterface $storeManager Turns the id the URL carries into the code the
     *        config reader and the deployment-config check key on
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * The scope a request addresses through its `store` or `website` parameter.
     *
     * Store wins over website because that is the precedence `Magento\Config\Block\System\Config\Form`
     * applies to the same two parameters. A parameter that is absent, not numeric, or 0 means default
     * scope: 0 is the admin store, which other admin pages put in their URL, and reading
     * configuration "at the admin store" means default scope anyway.
     *
     * @param RequestInterface $request
     * @return ConfigScope
     * @throws NoSuchEntityException When the id names no existing website or store
     */
    public function fromRequest(RequestInterface $request): ConfigScope
    {
        $storeId = $this->positiveId($request->getParam(ConfigScope::PARAM_STORE));
        if ($storeId !== null) {
            return $this->resolve(ScopeInterface::SCOPE_STORES, $storeId);
        }

        $websiteId = $this->positiveId($request->getParam(ConfigScope::PARAM_WEBSITE));

        return $websiteId === null
            ? $this->defaultScope()
            : $this->resolve(ScopeInterface::SCOPE_WEBSITES, $websiteId);
    }

    /**
     * The scope a rendered config field belongs to, from the `scope` and `scope_id` its form set.
     *
     * @param string $type Scope type as the config form sets it: `default`, `websites` or `stores`
     * @param mixed $id Scope id as the config form sets it, which is an empty string at default scope
     * @return ConfigScope
     * @throws NoSuchEntityException When the id names no existing website or store
     */
    public function fromScopeId(string $type, mixed $id): ConfigScope
    {
        $scopeId = $this->positiveId($id);

        return $scopeId === null ? $this->defaultScope() : $this->resolve($type, $scopeId);
    }

    /**
     * Build the scope for a website or store id, looking up its code.
     *
     * @param string $type
     * @param int $id
     * @return ConfigScope
     * @throws NoSuchEntityException
     */
    private function resolve(string $type, int $id): ConfigScope
    {
        return match ($type) {
            ScopeInterface::SCOPE_WEBSITES, ScopeInterface::SCOPE_WEBSITE => new ConfigScope(
                ScopeInterface::SCOPE_WEBSITES,
                $id,
                (string) $this->storeManager->getWebsite($id)->getCode(),
            ),
            ScopeInterface::SCOPE_STORES, ScopeInterface::SCOPE_STORE => new ConfigScope(
                ScopeInterface::SCOPE_STORES,
                $id,
                (string) $this->storeManager->getStore($id)->getCode(),
            ),
            default => $this->defaultScope(),
        };
    }

    /**
     * The default scope, which has neither id nor code.
     *
     * @return ConfigScope
     */
    private function defaultScope(): ConfigScope
    {
        return new ConfigScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0, '');
    }

    /**
     * A strictly positive integer id, or null for anything else a URL parameter can carry.
     *
     * Request parameters are whatever the caller put in the URL, an array included, and casting one
     * of those to int would quietly address website or store 1.
     *
     * @param mixed $value
     * @return int|null
     */
    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }
}

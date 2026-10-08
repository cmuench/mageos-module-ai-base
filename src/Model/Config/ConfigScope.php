<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The configuration scope an administrator is editing: default, one website, or one store view.
 *
 * Carried as type, numeric id and code together because each consumer needs a different one of
 * them, and none of them can be derived from another without a store lookup: the config writer
 * takes the id, `ScopeConfigInterface::getValue()` and `SettingChecker` take the code (a
 * deployment-config placeholder such as `CONFIG__WEBSITES__BASE__...` is keyed on the code, so an
 * id there silently matches nothing), and the admin page's own URL carries the id as its `website`
 * or `store` parameter, which is what the form's buttons send back.
 *
 * Built by {@see ConfigScopeResolver}, which is the one place that turns an id into a code.
 */
class ConfigScope
{
    /**
     * Request parameter the config edit page uses for a website scope, as Magento's own form reads it.
     */
    public const PARAM_WEBSITE = 'website';

    /**
     * Request parameter the config edit page uses for a store view scope, as Magento's own form reads it.
     */
    public const PARAM_STORE = 'store';

    /**
     * @param string $type `default`, `websites` or `stores`, the plural forms the config tables use
     * @param int $id Website or store id; 0 at default scope
     * @param string $code Website or store code; empty at default scope
     */
    public function __construct(
        private readonly string $type,
        private readonly int $id,
        private readonly string $code,
    ) {
    }

    /**
     * Scope type in the plural form `core_config_data` and `ScopeConfigInterface` use.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Website or store id, which is what `WriterInterface::save()` stores the row under.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Website or store code, or null at default scope, ready for `ScopeConfigInterface::getValue()`.
     *
     * Null rather than an empty string at default scope because that is what the reader expects
     * there, and an empty string for a website would read the website tree's root instead.
     *
     * @return string|null
     */
    public function getCode(): ?string
    {
        return $this->code === '' ? null : $this->code;
    }

    /**
     * Whether this is the default scope, which has no website or store to name.
     *
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->type === ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
    }

    /**
     * The request parameters that address this scope, exactly as the config edit page's URL does.
     *
     * The form's AJAX buttons send these back so the controller resolves the same scope the page is
     * showing. Empty at default scope, which is what a request without either parameter means.
     *
     * @return array<string,string>
     */
    public function toRequestParams(): array
    {
        return match ($this->type) {
            ScopeInterface::SCOPE_WEBSITES => [self::PARAM_WEBSITE => (string) $this->id],
            ScopeInterface::SCOPE_STORES => [self::PARAM_STORE => (string) $this->id],
            default => [],
        };
    }
}

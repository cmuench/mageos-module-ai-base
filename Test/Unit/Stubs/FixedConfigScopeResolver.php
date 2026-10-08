<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;

/**
 * A {@see ConfigScopeResolver} that answers one fixed scope, so a test can put its subject "on the
 * config page of website 2" without a store manager behind it. Turning ids into codes is
 * {@see \MageOS\AiBase\Test\Unit\Model\Config\ConfigScopeResolverTest}'s business.
 */
final class FixedConfigScopeResolver extends ConfigScopeResolver
{
    /**
     * @param ConfigScope $scope
     */
    public function __construct(private readonly ConfigScope $scope)
    {
    }

    /**
     * The default scope, which is what a request naming no website or store resolves to.
     *
     * @return self
     */
    public static function atDefault(): self
    {
        return new self(new ConfigScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0, ''));
    }

    /**
     * @inheritdoc
     */
    public function fromRequest(RequestInterface $request): ConfigScope
    {
        return $this->scope;
    }

    /**
     * @inheritdoc
     */
    public function fromScopeId(string $type, mixed $id): ConfigScope
    {
        return $this->scope;
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\Config\ScopeInterface;

/**
 * A config scope that stays in the area it was given, which is all `App\State` needs to be built.
 */
final class FixedAreaScope implements ScopeInterface
{
    public function __construct(private string $scope = 'adminhtml')
    {
    }

    public function getCurrentScope()
    {
        return $this->scope;
    }

    public function setCurrentScope($scope)
    {
        $this->scope = (string) $scope;
    }
}

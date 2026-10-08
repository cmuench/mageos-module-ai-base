<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\Event\ManagerInterface;

/**
 * An event manager with no observers, which is what a model's save events reach in a unit test.
 */
final class NullEventManager implements ManagerInterface
{
    public function dispatch($eventName, array $data = [])
    {
    }
}

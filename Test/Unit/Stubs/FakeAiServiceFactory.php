<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

require_once __DIR__ . '/AiServiceInterfaceFactoryStub.php';

use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Api\Data\AiServiceInterfaceFactory;
use MageOS\AiBase\Model\AiService;

/**
 * An AiServiceInterfaceFactory that builds the real value object without an ObjectManager.
 *
 * Extends the factory (the generated one inside Magento, the stub elsewhere) and skips its
 * constructor, so it works in both places without mocking a generated class.
 */
final class FakeAiServiceFactory extends AiServiceInterfaceFactory
{
    public function __construct()
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data = []): AiServiceInterface
    {
        return new AiService(...$data);
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

require_once __DIR__ . '/FieldDescriptorInterfaceFactoryStub.php';

use MageOS\AiBase\Api\Data\FieldDescriptorInterface;
use MageOS\AiBase\Api\Data\FieldDescriptorInterfaceFactory;
use MageOS\AiBase\Model\FieldDescriptor;

/**
 * A FieldDescriptorInterfaceFactory that builds the real value object without an ObjectManager.
 *
 * Extends the factory (the generated one inside Magento, the stub elsewhere) and skips its
 * constructor, so it works in both places without mocking a generated class.
 */
final class FakeFieldDescriptorFactory extends FieldDescriptorInterfaceFactory
{
    public function __construct()
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data = []): FieldDescriptorInterface
    {
        return new FieldDescriptor(...$data);
    }
}

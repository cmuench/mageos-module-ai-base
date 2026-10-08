<?php

declare(strict_types=1);

/**
 * Test stand-in for the Magento-generated AiServiceInterfaceFactory.
 *
 * Same reasoning as FieldDescriptorInterfaceFactoryStub: the standalone checkout has no code
 * generator, and the class_exists guard keeps the generated class authoritative where it exists.
 */

namespace MageOS\AiBase\Api\Data;

if (!class_exists(AiServiceInterfaceFactory::class)) {
    /**
     * Minimal stand-in matching the generated factory's public API.
     */
    class AiServiceInterfaceFactory
    {
        /**
         * Create a configured service instance.
         *
         * @param array $data
         * @return AiServiceInterface
         */
        public function create(array $data = [])
        {
            return new \MageOS\AiBase\Model\AiService(...$data);
        }
    }
}

<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\App\Cache\TypeListInterface;

/**
 * A cache type list with no types, for config backend models that only touch it after a save.
 */
final class NullCacheTypeList implements TypeListInterface
{
    public function getTypes()
    {
        return [];
    }

    public function getTypeLabels()
    {
        return [];
    }

    public function getInvalidated()
    {
        return [];
    }

    public function invalidate($typeCode)
    {
    }

    public function cleanType($typeCode)
    {
    }
}

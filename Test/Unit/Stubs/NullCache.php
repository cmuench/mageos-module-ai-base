<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Stubs;

use Magento\Framework\App\CacheInterface;

/**
 * A cache that stores nothing, for building a model whose context needs one it never uses.
 */
final class NullCache implements CacheInterface
{
    public function getFrontend()
    {
        throw new \LogicException('NullCache has no frontend; the code under test was not expected to ask for one.');
    }

    public function load($identifier)
    {
        return false;
    }

    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        return true;
    }

    public function remove($identifier)
    {
        return true;
    }

    public function clean($tags = [])
    {
        return true;
    }
}

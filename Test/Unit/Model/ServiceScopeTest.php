<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model;

use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\ServiceScope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\ServiceScope
 */
final class ServiceScopeTest extends TestCase
{
    public function test_no_scope_is_established_outside_a_callback(): void
    {
        self::assertNull((new ServiceScope())->getCurrent());
    }

    public function test_the_scope_is_established_for_the_callback_and_its_result_returned(): void
    {
        $serviceScope = new ServiceScope();
        $website = new ConfigScope('websites', 2, 'second');

        $result = $serviceScope->run($website, static fn (): ?ConfigScope => $serviceScope->getCurrent());

        self::assertSame($website, $result);
        self::assertNull($serviceScope->getCurrent());
    }

    /**
     * A failing provider call must not leave the next lookup in the same request reading another
     * scope's credentials.
     */
    public function test_the_previous_scope_is_restored_when_the_callback_throws(): void
    {
        $serviceScope = new ServiceScope();

        try {
            $serviceScope->run(new ConfigScope('websites', 2, 'second'), static function (): never {
                throw new \RuntimeException('provider down');
            });
        } catch (\RuntimeException) {
        }

        self::assertNull($serviceScope->getCurrent());
    }

    public function test_a_nested_scope_gives_way_to_the_outer_one_when_it_ends(): void
    {
        $serviceScope = new ServiceScope();
        $website = new ConfigScope('websites', 2, 'second');

        $afterInner = $serviceScope->run($website, static function () use ($serviceScope): ?ConfigScope {
            $serviceScope->run(new ConfigScope('stores', 3, 'second_en'), static fn (): null => null);

            return $serviceScope->getCurrent();
        });

        self::assertSame($website, $afterInner);
    }
}

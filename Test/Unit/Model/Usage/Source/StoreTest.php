<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\AiBase\Model\Usage\Source\Store;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\Source\Store
 */
final class StoreTest extends TestCase
{
    /**
     * Admin, cron and CLI usage is recorded under store 0, which Magento's store view options
     * leave out, so without it those grid rows show an empty Store cell.
     */
    public function test_it_offers_store_zero_before_the_store_views(): void
    {
        $source = new Store(new FakeStoreViewOptions([['value' => '1', 'label' => 'Default Store View']]));

        $options = $source->toOptionArray();

        self::assertSame(['0', '1'], array_column($options, 'value'));
        self::assertSame('Admin, cron and CLI', (string) $options[0]['label']);
    }
}

/**
 * Magento's store view options, as a fixed list.
 */
final class FakeStoreViewOptions implements OptionSourceInterface
{
    /**
     * @param array<mixed> $options
     */
    public function __construct(private readonly array $options)
    {
    }

    public function toOptionArray(): array
    {
        return $this->options;
    }
}

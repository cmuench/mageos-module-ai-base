<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source for the usage listing's store column and filter.
 *
 * Magento's own store options list store views only, because store 0 is never something a
 * storefront grid shows. Usage is different: admin, cron and CLI calls are recorded under store 0
 * on purpose, and with Magento's options alone those rows render an empty Store cell and cannot be
 * filtered on. The label matches the dashboard's store switcher, so both name it the same way.
 */
class Store implements OptionSourceInterface
{
    /**
     * Store id that admin, cron and CLI usage is recorded under.
     */
    private const ADMIN_STORE_ID = '0';

    /**
     * @param OptionSourceInterface $storeViewOptions Magento's store view options, wired in di.xml
     */
    public function __construct(
        private readonly OptionSourceInterface $storeViewOptions,
    ) {
    }

    /**
     * Store 0 first, then every store view as Magento lists them.
     *
     * @return array<mixed>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ADMIN_STORE_ID, 'label' => __('Admin, cron and CLI')],
            ...$this->storeViewOptions->toOptionArray(),
        ];
    }
}

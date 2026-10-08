<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Usage;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Decides which store id a usage row is attributed to.
 *
 * `StoreManagerInterface::getStore()` alone is not enough: outside a storefront request it does
 * not report "no store", it reports the default store view, because the store resolver falls back
 * to it in cron and CLI. Recording that would put every cron job's and every CLI command's spend
 * on the default store view, while the dashboard's "Admin, cron and CLI" option and the
 * documentation promise store 0 for exactly that traffic. Once written, such rows cannot be told
 * apart from real default-store-view traffic, so the attribution has to be right at record time.
 *
 * The rule:
 * - In a storefront area ({@see STOREFRONT_AREAS}) the current store is the one the request was
 *   made in, and is recorded as is.
 * - Anywhere else (admin, cron, CLI, or no area set at all) the current store is recorded only when
 *   it differs from the default store view. That means code switched to a specific store on
 *   purpose, for instance a cron job emulating store 2 to generate that store's content, and the
 *   spend genuinely belongs to it. Otherwise the call is recorded under store 0.
 *
 * The known limitation of that rule: non-storefront code that emulates the default store view
 * itself cannot be told apart from code that emulates nothing, so its calls are recorded under
 * store 0 rather than under the default store view.
 */
class UsageStoreResolver
{
    /**
     * Store id for calls made outside any storefront store: admin, cron and CLI.
     */
    public const ADMIN_STORE_ID = 0;

    /**
     * Areas that serve a customer-facing request, where the current store is the store the
     * request was actually made in rather than a fallback.
     *
     * @var string[]
     */
    private const STOREFRONT_AREAS = [
        Area::AREA_FRONTEND,
        Area::AREA_WEBAPI_REST,
        Area::AREA_WEBAPI_SOAP,
        Area::AREA_GRAPHQL,
    ];

    /**
     * @param StoreManagerInterface $storeManager Names the current store and the default store
     *        view it has to be compared against outside a storefront area
     * @param State $appState Tells a storefront request apart from admin, cron and CLI
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
    ) {
    }

    /**
     * The store id to record a call under; see the class docblock for the rule.
     *
     * Never throws for a store lookup that fails: recording runs alongside an AI call that may
     * already have succeeded, and a store that cannot be resolved must not turn that into a
     * failure. Such a call is recorded under store 0, the same as one made with no store at all.
     *
     * @return int
     */
    public function getStoreId(): int
    {
        try {
            return $this->isStorefrontArea()
                ? (int) $this->storeManager->getStore()->getId()
                : $this->getEmulatedStoreId();
        } catch (\Throwable) {
            return self::ADMIN_STORE_ID;
        }
    }

    /**
     * The store non-storefront code switched to, or store 0 when it did not switch.
     *
     * "Switched" means the current store is anything other than the default store view. The
     * default store view is what the store resolver hands back when nothing chose a store, so
     * finding it is no evidence that anything did; any other store is.
     *
     * @return int
     */
    private function getEmulatedStoreId(): int
    {
        $currentStoreId = (int) $this->storeManager->getStore()->getId();
        $defaultStoreViewId = $this->storeManager->getDefaultStoreView()?->getId();

        return $defaultStoreViewId === null || $currentStoreId === (int) $defaultStoreViewId
            ? self::ADMIN_STORE_ID
            : $currentStoreId;
    }

    /**
     * Whether the code running this call serves a storefront request.
     *
     * An area code that was never set (a bare script, some CLI paths) makes {@see State} throw;
     * that is treated as non-storefront, since a storefront request always has its area set.
     *
     * @return bool
     */
    private function isStorefrontArea(): bool
    {
        try {
            return in_array($this->appState->getAreaCode(), self::STOREFRONT_AREAS, true);
        } catch (LocalizedException) {
            return false;
        }
    }
}

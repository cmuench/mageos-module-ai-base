<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config;

use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The config edit page addresses its scope as `website/<id>` or `store/<id>`; the resolver has to
 * read those the way Magento's own config form does and name the scope by code, which is what the
 * config reader and the deployment-config check key on.
 *
 * @covers \MageOS\AiBase\Model\Config\ConfigScopeResolver
 * @covers \MageOS\AiBase\Model\Config\ConfigScope
 */
final class ConfigScopeResolverTest extends TestCase
{
    public function test_a_request_without_scope_parameters_is_default_scope(): void
    {
        $scope = $this->resolver()->fromRequest($this->request([]));

        self::assertTrue($scope->isDefault());
        self::assertSame('default', $scope->getType());
        self::assertNull($scope->getCode());
        self::assertSame([], $scope->toRequestParams());
    }

    public function test_a_website_parameter_names_that_website_by_code(): void
    {
        $scope = $this->resolver()->fromRequest($this->request(['website' => '2']));

        self::assertSame(['websites', 2, 'second'], [$scope->getType(), $scope->getId(), $scope->getCode()]);
        self::assertSame(['website' => '2'], $scope->toRequestParams());
    }

    /**
     * Magento's config form reads the store parameter first, so a URL carrying both is a store view.
     */
    public function test_a_store_parameter_wins_over_a_website_parameter(): void
    {
        $scope = $this->resolver()->fromRequest($this->request(['website' => '2', 'store' => '3']));

        self::assertSame(['stores', 3, 'second_en'], [$scope->getType(), $scope->getId(), $scope->getCode()]);
        self::assertSame(['store' => '3'], $scope->toRequestParams());
    }

    /**
     * @param mixed $value
     */
    #[DataProvider('parameters_that_name_no_scope')]
    public function test_a_parameter_that_names_no_scope_means_default(mixed $value): void
    {
        self::assertTrue($this->resolver()->fromRequest($this->request(['website' => $value]))->isDefault());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function parameters_that_name_no_scope(): array
    {
        return [
            'empty string' => [''],
            'zero, the admin store' => ['0'],
            'not a number' => ['base'],
            'an array from website[]=2' => [['2']],
            'negative' => ['-2'],
        ];
    }

    public function test_the_scope_of_a_rendered_field_is_read_from_its_scope_id(): void
    {
        $scope = $this->resolver()->fromScopeId('websites', 2);

        self::assertSame(['websites', 2, 'second'], [$scope->getType(), $scope->getId(), $scope->getCode()]);
    }

    /**
     * The config form sets an empty scope_id at default scope.
     */
    public function test_a_rendered_field_at_default_scope_is_default(): void
    {
        self::assertTrue($this->resolver()->fromScopeId('default', '')->isDefault());
    }

    private function resolver(): ConfigScopeResolver
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('second');
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('second_en');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturnMap([[2, $website]]);
        $storeManager->method('getStore')->willReturnMap([[3, $store]]);

        return new ConfigScopeResolver($storeManager);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name) => $params[$name] ?? null
        );

        return $request;
    }
}

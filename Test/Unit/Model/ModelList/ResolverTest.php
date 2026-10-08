<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\ModelList;

use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\ModelList\Resolver;
use MageOS\AiBase\Model\ModelList\Storage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\ModelList\Resolver
 */
final class ResolverTest extends TestCase
{
    private Storage&MockObject $storage;
    private Resolver $subject;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(Storage::class);
        $this->subject = new Resolver($this->storage);
    }

    public function test_a_legacy_per_code_list_wins_over_curated_defaults(): void
    {
        $this->storage->method('getLegacyModels')->with('openai')
            ->willReturn(['gpt-5' => 'GPT-5']);

        $service = $this->serviceMock('openai', ['gpt-4o' => 'GPT-4o']);

        self::assertSame(['gpt-5' => 'GPT-5'], $this->subject->getModels($service));
    }

    public function test_falls_back_to_supported_models_when_nothing_stored(): void
    {
        $this->storage->method('getLegacyModels')->with('openai')->willReturn(null);

        $service = $this->serviceMock('openai', ['gpt-4o' => 'GPT-4o']);

        self::assertSame(['gpt-4o' => 'GPT-4o'], $this->subject->getModels($service));
    }

    public function test_falls_back_to_supported_models_when_stored_list_is_empty(): void
    {
        $this->storage->method('getLegacyModels')->with('openai')->willReturn([]);

        $service = $this->serviceMock('openai', ['gpt-4o' => 'GPT-4o']);

        self::assertSame(['gpt-4o' => 'GPT-4o'], $this->subject->getModels($service));
    }

    /**
     * The row's list came from the endpoint that row points at, so it wins over a list another
     * row of the same provider refreshed before lists were stored per row.
     */
    public function test_a_row_own_list_wins_over_the_legacy_per_code_list(): void
    {
        $this->storage->method('getModelsForRow')->willReturnCallback(
            static fn (string $rowId): ?array => $rowId === '_host_a' ? ['llama3' => 'llama3'] : null
        );
        $this->storage->method('getLegacyModels')->willReturn(['qwen3' => 'qwen3']);

        $models = $this->subject->getModelsForRow(
            $this->serviceMock('ollama', []),
            '_host_a',
            $this->defaultScope()
        );

        self::assertSame(['llama3' => 'llama3'], $models);
    }

    /**
     * An install that refreshed before lists became per row keeps its suggestions until each row is
     * refreshed again.
     */
    public function test_a_row_never_refreshed_falls_back_to_the_legacy_per_code_list(): void
    {
        $this->storage->method('getModelsForRow')->willReturn(null);
        $this->storage->method('getLegacyModels')->with('ollama')->willReturn(['qwen3' => 'qwen3']);

        $models = $this->subject->getModelsForRow(
            $this->serviceMock('ollama', ['curated' => 'Curated']),
            '_host_b',
            $this->defaultScope()
        );

        self::assertSame(['qwen3' => 'qwen3'], $models);
    }

    public function test_a_row_with_nothing_stored_anywhere_gets_the_curated_defaults(): void
    {
        $this->storage->method('getModelsForRow')->willReturn([]);
        $this->storage->method('getLegacyModels')->willReturn(null);

        $models = $this->subject->getModelsForRow(
            $this->serviceMock('openai', ['gpt-4o' => 'GPT-4o']),
            '_row',
            $this->defaultScope()
        );

        self::assertSame(['gpt-4o' => 'GPT-4o'], $models);
    }

    /**
     * Build a service configuration stub with the given code and curated model list.
     *
     * @param string $code
     * @param array<string, string> $supportedModels
     * @return AiServiceConfigurationInterface&MockObject
     */
    private function serviceMock(string $code, array $supportedModels): AiServiceConfigurationInterface&MockObject
    {
        $service = $this->createMock(AiServiceConfigurationInterface::class);
        $service->method('getCode')->willReturn($code);
        $service->method('getSupportedModels')->willReturn($supportedModels);

        return $service;
    }

    private function defaultScope(): ConfigScope
    {
        return new ConfigScope('default', 0, '');
    }
}

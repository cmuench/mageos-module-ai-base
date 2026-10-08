<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Usage\Source;

use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Api\Data\AiServiceConfigurationInterface;
use MageOS\AiBase\Model\AiService;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Api\Data\AiServiceInterface;
use MageOS\AiBase\Model\Usage\ServiceRowLabels;
use MageOS\AiBase\Model\Usage\Source\ServiceRow;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\Usage\Source\ServiceRow
 * @covers \MageOS\AiBase\Model\Usage\ServiceRowLabels
 */
final class ServiceRowTest extends TestCase
{
    private FakeRowServiceSelector $serviceSelector;

    protected function setUp(): void
    {
        $this->serviceSelector = new FakeRowServiceSelector();
    }

    /**
     * service_id alone is an opaque JSON object key; an administrator recognises the provider
     * name, not the row key, so a registered provider's row is offered under its human name.
     */
    public function test_it_labels_a_service_row_with_its_provider_name_when_the_code_is_registered(): void
    {
        $this->serviceSelector->withServices([new AiService('_row_a', 'openai', [])]);

        $options = $this->subject(['openai' => 'OpenAI'])->toOptionArray();

        self::assertSame('_row_a', $options[0]['value']);
        self::assertSame('OpenAI', (string) $options[0]['label']);
    }

    /**
     * A row can outlive the module that registered its provider, so an unregistered code still
     * has to be selectable rather than vanish from the filter.
     */
    public function test_it_falls_back_to_the_raw_code_when_the_service_is_not_registered(): void
    {
        $this->serviceSelector->withServices([new AiService('_row_a', 'long_gone', [])]);

        $options = $this->subject([])->toOptionArray();

        self::assertSame('long_gone', (string) $options[0]['label']);
    }

    public function test_it_labels_a_service_row_with_the_name_it_was_given(): void
    {
        $this->serviceSelector->withServices([
            new AiService('_row_a', 'anthropic', [AiServiceInterface::CONFIGURATION_LABEL => 'Chat AI']),
        ]);

        $options = $this->subject(['anthropic' => 'Anthropic'])->toOptionArray();

        self::assertSame('Chat AI', $options[0]['label']);
    }

    public function test_it_offers_two_named_rows_of_the_same_provider_as_two_distinct_options(): void
    {
        $this->serviceSelector->withServices([
            new AiService('_row_a', 'anthropic', [AiServiceInterface::CONFIGURATION_LABEL => 'Chat AI']),
            new AiService('_row_b', 'anthropic', [AiServiceInterface::CONFIGURATION_LABEL => 'Summaries']),
        ]);

        $options = $this->subject(['anthropic' => 'Anthropic'])->toOptionArray();

        self::assertSame(
            [['value' => '_row_a', 'label' => 'Chat AI'], ['value' => '_row_b', 'label' => 'Summaries']],
            $options
        );
    }

    public function test_it_appends_the_row_id_when_two_rows_would_read_the_same(): void
    {
        $this->serviceSelector->withServices([
            new AiService('_row_a', 'anthropic', []),
            new AiService('_row_b', 'anthropic', []),
        ]);

        $options = $this->subject(['anthropic' => 'Anthropic'])->toOptionArray();

        self::assertSame(['Anthropic (_row_a)', 'Anthropic (_row_b)'], array_column($options, 'label'));
    }

    public function test_it_offers_a_numeric_row_id_as_a_string_value(): void
    {
        $this->serviceSelector->withServices([new AiService('12', 'openai', [])]);

        $options = $this->subject(['openai' => 'OpenAI'])->toOptionArray();

        self::assertSame('12', $options[0]['value']);
    }

    /**
     * @param array<string,string> $names Service code => display name
     */
    private function subject(array $names): ServiceRow
    {
        return new ServiceRow(new ServiceRowLabels($this->serviceSelector, $this->registry($names)));
    }

    /**
     * @param array<string,string> $names Service code => display name
     * @return ServiceRegistry
     */
    private function registry(array $names): ServiceRegistry
    {
        return new ServiceRegistry(array_map(
            static fn (string $code, string $name): AiServiceConfigurationInterface
                => new FakeRegisteredService($code, $name),
            array_keys($names),
            $names,
        ));
    }
}

/**
 * In-memory stand-in for {@see AiServiceSelectorInterface} holding a canned list of configured
 * rows, per this codebase's fakes-over-mocks convention.
 */
final class FakeRowServiceSelector implements AiServiceSelectorInterface
{
    /** @var AiServiceInterface[] */
    private array $services = [];

    /**
     * @param AiServiceInterface[] $services
     */
    public function withServices(array $services): self
    {
        $this->services = $services;

        return $this;
    }

    /**
     * @return AiServiceInterface[]
     */
    public function getAll(): array
    {
        return $this->services;
    }

    /**
     * @return AiServiceInterface[]
     */
    public function getByCode(string $code): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (AiServiceInterface $service): bool => $service->getCode() === $code
        ));
    }

    public function getById(string $id): ?AiServiceInterface
    {
        foreach ($this->services as $service) {
            if ($service->getId() === $id) {
                return $service;
            }
        }

        return null;
    }
}

/**
 * A registered provider reduced to the code and display name {@see ServiceRow} reads off it.
 */
final class FakeRegisteredService implements AiServiceConfigurationInterface
{
    public function __construct(private readonly string $code, private readonly string $name)
    {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return \MageOS\AiBase\Api\Data\FieldDescriptorInterface[]
     */
    public function getConfigurationFields(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getSupportedModels(): array
    {
        return [];
    }
}

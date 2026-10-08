<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\ModelList;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\ModelList\Storage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MageOS\AiBase\Model\ModelList\Storage
 */
final class StorageTest extends TestCase
{
    private WriterInterface&MockObject $configWriter;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private TypeListInterface&MockObject $cacheTypeList;
    private Storage $subject;

    protected function setUp(): void
    {
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);

        $this->subject = new Storage(
            $this->configWriter,
            $this->scopeConfig,
            new Json(),
            $this->cacheTypeList,
        );
    }

    public function test_save_for_row_writes_json_payload_with_timestamp_and_cleans_config_cache(): void
    {
        $written = [];
        $this->configWriter->expects(self::once())->method('save')
            ->willReturnCallback(function (string $path, $value, string $scope, int $scopeId) use (&$written) {
                $written = [$path, $value, $scope, $scopeId];
            });
        $this->cacheTypeList->expects(self::once())->method('cleanType')->with('config');

        $before = time();
        $this->subject->saveForRow('_1712345678901_901', ['gpt-4o' => 'GPT-4o'], $this->defaultScope());

        self::assertSame(
            ['mageos_ai/services/row_models/_1712345678901_901', 'default', 0],
            [$written[0], $written[2], $written[3]]
        );
        $payload = json_decode($written[1], true);
        self::assertSame(['gpt-4o' => 'GPT-4o'], $payload['models']);
        self::assertGreaterThanOrEqual($before, $payload['fetched_at']);
        self::assertLessThanOrEqual(time(), $payload['fetched_at']);
    }

    /**
     * A website that overrides the services carries copies of default's row ids pointed somewhere
     * else, so its refreshed list is stored on the website rather than over default's.
     */
    public function test_save_for_row_writes_at_the_scope_it_was_refreshed_in(): void
    {
        $written = [];
        $this->configWriter->method('save')
            ->willReturnCallback(function (string $path, $value, string $scope, int $scopeId) use (&$written) {
                $written = [$scope, $scopeId];
            });

        $this->subject->saveForRow('_row', [], new ConfigScope('websites', 2, 'second'));

        self::assertSame(['websites', 2], $written);
    }

    /**
     * Two rows of one provider on different hosts must not share, and so overwrite, one list.
     */
    public function test_two_rows_of_one_provider_are_stored_apart(): void
    {
        $paths = [];
        $this->configWriter->method('save')->willReturnCallback(
            function (string $path) use (&$paths) {
                $paths[] = $path;
            }
        );

        $this->subject->saveForRow('_ollama_host_a', ['llama3' => 'llama3'], $this->defaultScope());
        $this->subject->saveForRow('_ollama_host_b', ['qwen3' => 'qwen3'], $this->defaultScope());

        self::assertSame(
            ['mageos_ai/services/row_models/_ollama_host_a', 'mageos_ai/services/row_models/_ollama_host_b'],
            $paths
        );
    }

    /**
     * Row ids are POST array keys. A `/` in one would nest the config path, and the reader would
     * hand back an array where the payload belongs, so such an id is hashed into one segment.
     */
    public function test_a_row_id_that_cannot_be_a_path_segment_is_hashed_into_one(): void
    {
        $path = null;
        $this->configWriter->method('save')->willReturnCallback(
            function (string $written) use (&$path) {
                $path = $written;
            }
        );

        $this->subject->saveForRow('a/b', [], $this->defaultScope());

        self::assertSame('mageos_ai/services/row_models/' . sha1('a/b'), $path);
    }

    public function test_get_models_for_row_decodes_the_payload_stored_at_the_given_scope(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('mageos_ai/services/row_models/_row', 'websites', 'second')
            ->willReturn('{"fetched_at":1750000000,"models":{"gpt-4o":"GPT-4o","o1":"o1"}}');

        self::assertSame(
            ['gpt-4o' => 'GPT-4o', 'o1' => 'o1'],
            $this->subject->getModelsForRow('_row', new ConfigScope('websites', 2, 'second'))
        );
    }

    /**
     * Lists refreshed before they became per row were stored per provider code, and an upgraded
     * install keeps reading them until each row is refreshed again.
     */
    public function test_get_legacy_models_reads_the_list_stored_per_code_before_lists_became_per_row(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('mageos_ai/services/models/openai')
            ->willReturn('{"fetched_at":1750000000,"models":{"gpt-4o":"GPT-4o"}}');

        self::assertSame(['gpt-4o' => 'GPT-4o'], $this->subject->getLegacyModels('openai'));
    }

    public function test_get_models_returns_null_when_nothing_stored(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertNull($this->subject->getModelsForRow('_row', $this->defaultScope()));
        self::assertNull($this->subject->getLegacyModels('openai'));
    }

    public function test_get_models_returns_null_on_malformed_payload(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('{not json');

        self::assertNull($this->subject->getModelsForRow('_row', $this->defaultScope()));
        self::assertNull($this->subject->getLegacyModels('openai'));
    }

    /**
     * The payload is read back out of core_config_data, so a hand-edited row can hold anything,
     * and the admin form renders these entries straight into option labels.
     */
    public function test_get_models_drops_entries_whose_label_is_not_a_string(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturn('{"fetched_at":1750000000,"models":{"gpt-4o":"GPT-4o","o1":{"nested":true},"o3":null}}');

        self::assertSame(['gpt-4o' => 'GPT-4o'], $this->subject->getModelsForRow('_row', $this->defaultScope()));
    }

    private function defaultScope(): ConfigScope
    {
        return new ConfigScope('default', 0, '');
    }
}

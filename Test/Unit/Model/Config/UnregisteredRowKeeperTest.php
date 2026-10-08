<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config;

require_once __DIR__ . '/../../Stubs/FakeFieldDescriptorFactory.php';

use MageOS\AiBase\AiServices\OpenAi;
use MageOS\AiBase\Api\JsonFetcherInterface;
use MageOS\AiBase\Model\Config\UnregisteredRowKeeper;
use MageOS\AiBase\Model\ServiceRegistry;
use MageOS\AiBase\Test\Unit\Stubs\FakeFieldDescriptorFactory;
use PHPUnit\Framework\TestCase;

/**
 * A save replaces the stored rows with the posted ones, and the form cannot post a row whose
 * provider is no longer registered. These pin which stored rows survive a post that left them out.
 *
 * @covers \MageOS\AiBase\Model\Config\UnregisteredRowKeeper
 */
final class UnregisteredRowKeeperTest extends TestCase
{
    private const OPENAI_ROW = ['openai' => ['api_key' => '0:3:enc(sk-openai)', 'model' => 'gpt-4o']];

    private const ACME_ROW = ['acme_removed' => ['api_key' => '0:3:enc(sk-acme)', '_label' => 'Old gateway']];

    public function test_an_unregistered_row_left_out_of_the_post_is_kept_as_stored(): void
    {
        $kept = $this->keeper()->keep(['_openai' => self::OPENAI_ROW], $this->stored(), []);

        self::assertSame(['_openai' => self::OPENAI_ROW, '_acme' => self::ACME_ROW], $kept);
    }

    public function test_a_registered_row_left_out_of_the_post_is_not_brought_back(): void
    {
        $kept = $this->keeper()->keep([], $this->stored(), []);

        self::assertSame(['_acme' => self::ACME_ROW], $kept);
    }

    public function test_an_unregistered_row_whose_id_is_in_the_deletion_list_is_dropped(): void
    {
        $kept = $this->keeper()->keep(['_openai' => self::OPENAI_ROW], $this->stored(), ['_acme']);

        self::assertSame(['_openai' => self::OPENAI_ROW], $kept);
    }

    /**
     * The post is the more deliberate statement about a row id, so a posted row is never replaced
     * by what happens to be stored under the same id.
     */
    public function test_a_posted_row_wins_over_a_stored_row_with_the_same_id(): void
    {
        $posted = ['_acme' => ['acme_removed' => ['api_key' => '0:3:enc(sk-new)']]];

        $kept = $this->keeper()->keep($posted, $this->stored(), []);

        self::assertSame($posted, $kept);
    }

    /**
     * Only rows the form can show as a placeholder are kept, so every kept row can also be deleted
     * from the form.
     */
    public function test_a_malformed_stored_row_is_not_kept(): void
    {
        $stored = ['_scalar' => 'not a row', '_flat' => ['acme_removed' => 'not an array'], '_numeric' => [7 => []]];

        $kept = $this->keeper()->keep([], $stored, []);

        self::assertSame([], $kept);
    }

    public function test_nothing_stored_adds_nothing(): void
    {
        $kept = $this->keeper()->keep(['_openai' => self::OPENAI_ROW], [], ['_acme']);

        self::assertSame(['_openai' => self::OPENAI_ROW], $kept);
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private function stored(): array
    {
        return ['_openai' => self::OPENAI_ROW, '_acme' => self::ACME_ROW];
    }

    private function keeper(): UnregisteredRowKeeper
    {
        $fetcher = new class implements JsonFetcherInterface {
            public function getJson(string $url, array $headers = []): array
            {
                return [];
            }
        };

        return new UnregisteredRowKeeper(
            new ServiceRegistry([new OpenAi(new FakeFieldDescriptorFactory(), $fetcher)])
        );
    }
}

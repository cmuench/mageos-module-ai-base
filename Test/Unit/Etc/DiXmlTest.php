<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Guards the parts of di.xml whose mistakes only show up as a provider rejecting the request.
 */
final class DiXmlTest extends TestCase
{
    private SimpleXMLElement $config;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/src/etc/di.xml';
        $this->config = new SimpleXMLElement((string) file_get_contents($path));
    }

    /**
     * Anthropic's Messages API is the one bridge known today whose reported prompt count
     * excludes cache reads and writes, so only its bridge entry may declare the flag true.
     */
    public function test_it_declares_cache_outside_prompt_true_for_anthropic_only(): void
    {
        $bridgeItems = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\BridgeRegistry"]/arguments/argument[@name="bridges"]/item',
        );
        self::assertNotEmpty($bridgeItems);

        foreach ($bridgeItems as $bridgeItem) {
            $serviceCode = (string) $bridgeItem['name'];
            $flag = $bridgeItem->xpath('item[@name="cache_outside_prompt"]')[0] ?? null;

            if ($serviceCode === 'anthropic') {
                self::assertNotNull($flag, $serviceCode);
                self::assertSame('true', (string) $flag);
                continue;
            }

            self::assertNull($flag, $serviceCode);
        }
    }

    /**
     * symfony/ai's Gemini bridge nests every option under generationConfig except `tool_config`,
     * which it lifts to the top of the request. Spelled `toolConfig`, a tool choice stays nested
     * and Gemini answers every call that sets one with a 400.
     */
    public function test_every_gemini_tool_choice_lands_under_the_key_the_bridge_lifts_out(): void
    {
        $fragments = $this->config->xpath(
            '//type[@name="MageOS\AiBase\Model\Client\OptionNormalizer"]/arguments/argument[@name="dialects"]'
            . '/item[@name="gemini"]/item[@name="values"]/item[@name="tool_choice"]/item',
        );
        self::assertNotEmpty($fragments);

        foreach ($fragments as $fragment) {
            $keys = array_map(
                static fn (SimpleXMLElement $item): string => (string) $item['name'],
                $fragment->xpath('item'),
            );

            self::assertSame(['tool_config'], $keys, (string) $fragment['name']);
        }
    }
}

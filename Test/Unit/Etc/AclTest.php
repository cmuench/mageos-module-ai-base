<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Etc;

use PHPUnit\Framework\TestCase;

/**
 * The declarative permission tree in acl.xml: which resources exist, and how they nest.
 *
 * Parsed as plain XML rather than resolved through Magento's Acl builder, since the tree itself
 * carries no behaviour to exercise, only structure to get right.
 */
final class AclTest extends TestCase
{
    public function test_it_declares_a_usage_acl_resource_separate_from_the_configuration_resource(): void
    {
        $usage = $this->findResourceById('MageOS_AiBase::usage');
        $configuration = $this->findResourceById('MageOS_AiBase::configuration');

        self::assertNotNull($usage);
        self::assertNotNull($configuration);
        self::assertNotSame($usage, $configuration);
        self::assertSame('Mage-OS AI Usage', (string) $usage['title']);
    }

    /**
     * The configuration resource guards provider credentials and the outbound Test and Refresh
     * requests. Nested under Attributes, granting a merchandiser role "Attributes" granted all of
     * that too; under Magento_Config::config it comes only with the right to edit configuration,
     * like every other configuration section.
     */
    public function test_it_nests_the_configuration_resource_with_the_other_configuration_sections(): void
    {
        $configuration = $this->findResourceById('MageOS_AiBase::configuration');

        self::assertNotNull($configuration);
        self::assertSame(
            ['Magento_Backend::admin', 'Magento_Backend::stores', 'Magento_Backend::stores_settings', 'Magento_Config::config'],
            $this->ancestorIds($configuration)
        );
    }

    public function test_it_declares_no_resource_under_stores_attributes(): void
    {
        self::assertNull($this->findResourceById('Magento_Backend::stores_attributes'));
    }

    public function test_it_groups_the_ai_reports_under_their_own_resource(): void
    {
        $group = $this->findResourceById('MageOS_AiBase::reports');

        self::assertNotNull($group);
        self::assertSame('Magento_Reports::report', (string) $group->xpath('..')[0]['id']);
    }

    public function test_it_declares_the_reports_branch_inside_the_admin_root(): void
    {
        $reports = $this->findResourceById('Magento_Reports::report');

        self::assertNotNull($reports);

        // Magento_Reports declares this resource under Magento_Backend::admin. Naming it anywhere
        // else does not move it: acl.xml files merge into one document under a unique-id
        // constraint, so a second position is a duplicate and the merged ACL fails to build.
        self::assertSame('Magento_Backend::admin', (string) $reports->xpath('..')[0]['id']);
    }

    public function test_it_nests_the_usage_acl_resource_under_the_reports_branch(): void
    {
        $usage = $this->findResourceById('MageOS_AiBase::usage');

        self::assertNotNull($usage);
        self::assertSame('MageOS_AiBase::reports', (string) $usage->xpath('..')[0]['id']);
    }

    public function test_it_validates_against_the_acl_schema(): void
    {
        $document = new \DOMDocument();
        $document->load(__DIR__ . '/../../../src/etc/acl.xml');

        self::assertTrue($document->schemaValidate(
            (new \Magento\Framework\Config\Dom\UrnResolver())->getRealPath('urn:magento:framework:Acl/etc/acl.xsd')
        ));
    }

    /**
     * Ids of a resource's ancestors, outermost first.
     *
     * @return list<string>
     */
    private function ancestorIds(\SimpleXMLElement $resource): array
    {
        return array_map(
            static fn (\SimpleXMLElement $ancestor): string => (string) $ancestor['id'],
            $resource->xpath('ancestor::resource')
        );
    }

    private function findResourceById(string $id): ?\SimpleXMLElement
    {
        $acl = simplexml_load_file(__DIR__ . '/../../../src/etc/acl.xml');
        $matches = $acl->xpath("//resource[@id='" . $id . "']");

        return $matches[0] ?? null;
    }
}

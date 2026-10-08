<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Block\Adminhtml\Configuration;

require_once __DIR__ . '/../../../Stubs/FakeSettingChecker.php';
require_once __DIR__ . '/../../../Stubs/FixedConfigScopeResolver.php';

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use MageOS\AiBase\Block\Adminhtml\Configuration\Services;
use MageOS\AiBase\Model\Config\ConfigScope;
use MageOS\AiBase\Model\Config\ConfigScopeResolver;
use MageOS\AiBase\Test\Unit\Stubs\FakeSettingChecker;
use MageOS\AiBase\Test\Unit\Stubs\FixedConfigScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * A field set in deployment configuration must say so instead of pretending to be editable, and a
 * field that merely inherits must not.
 *
 * `bin/magento app:config:dump` and `config:set --lock-env` write this path into `app/etc/env.php`,
 * and deployment configuration wins over the database. `Magento\Config\Model\Config` then skips the
 * field on save without reporting anything, and the section still answers "You saved the
 * configuration". This form builds every control itself, so it has to show that state on its own.
 *
 * It used to read the state off the element's `disabled` flag, which Magento's field renderer also
 * sets whenever "Use Default" or "Use Website" is ticked. Every inheriting website and store view
 * then showed the env.php warning and lost its add, rename and delete controls.
 */
final class ServicesReadOnlyTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';

    private FakeSettingChecker $settingChecker;

    protected function setUp(): void
    {
        if (!class_exists(AbstractFieldArray::class)) {
            self::markTestSkipped('magento/module-config is not installed in this environment.');
        }

        $this->settingChecker = new FakeSettingChecker();
    }

    public function test_a_field_locked_in_deployment_configuration_is_reported_read_only(): void
    {
        $this->settingChecker->givenLocked(self::CONFIG_PATH);

        self::assertTrue($this->blockForElement(new DataObject(['scope' => 'default']))->isReadOnly());
    }

    public function test_an_editable_field_is_not_reported_read_only(): void
    {
        self::assertFalse($this->blockForElement(new DataObject(['scope' => 'default']))->isReadOnly());
    }

    /**
     * Core's renderer disables an inherited field before the template runs. That is not a lock, and
     * reading it as one is what put the env.php warning on every inheriting scope.
     */
    public function test_an_inherited_field_that_core_disabled_is_not_reported_read_only(): void
    {
        $element = new DataObject([
            'scope' => 'websites',
            'scope_id' => 2,
            'inherit' => true,
            'can_use_default_value' => true,
            'disabled' => true,
        ]);

        self::assertFalse($this->blockForElement($element, $this->websiteScope())->isReadOnly());
    }

    /**
     * The save path asks about the scope being saved, so the form has to ask about the scope being
     * rendered: a lock on one website must not lock another, nor default.
     */
    public function test_the_lock_is_checked_at_the_scope_of_the_rendered_field(): void
    {
        $this->settingChecker->givenLocked(self::CONFIG_PATH, 'websites', 'second');

        $isReadOnly = $this->blockForElement(new DataObject(['scope' => 'websites']), $this->websiteScope())
            ->isReadOnly();

        self::assertTrue($isReadOnly);
        self::assertSame([[self::CONFIG_PATH, 'websites', 'second']], $this->settingChecker->getQuestions());
    }

    public function test_a_lock_on_another_website_leaves_this_one_editable(): void
    {
        $this->settingChecker->givenLocked(self::CONFIG_PATH, 'websites', 'third');

        self::assertFalse(
            $this->blockForElement(new DataObject(['scope' => 'websites']), $this->websiteScope())->isReadOnly()
        );
    }

    /**
     * The renderer is a layout singleton, so it is asked before the config form has handed it an
     * element at all. Answering read-only there would lock a form nothing is wrong with.
     */
    public function test_a_block_with_no_element_yet_is_not_reported_read_only(): void
    {
        $this->settingChecker->givenLocked(self::CONFIG_PATH);

        self::assertFalse($this->block(FixedConfigScopeResolver::atDefault())->isReadOnly());
    }

    public function test_a_ticked_use_default_box_is_reported_as_inherited(): void
    {
        $element = new DataObject(['inherit' => true, 'can_use_default_value' => true]);

        self::assertTrue($this->blockForElement($element)->isInherited());
    }

    public function test_a_ticked_use_website_box_is_reported_as_inherited(): void
    {
        $element = new DataObject(['inherit' => true, 'can_use_website_value' => true]);

        self::assertTrue($this->blockForElement($element)->isInherited());
    }

    /**
     * Default scope has no box to tick. The form reports `inherit` there whenever nothing is stored
     * yet, which is no reason to disable the controls of a fresh install.
     */
    public function test_default_scope_with_nothing_stored_is_not_reported_as_inherited(): void
    {
        self::assertFalse($this->blockForElement(new DataObject(['inherit' => true]))->isInherited());
    }

    public function test_an_overridden_website_is_not_reported_as_inherited(): void
    {
        $element = new DataObject(['inherit' => false, 'can_use_default_value' => true]);

        self::assertFalse($this->blockForElement($element)->isInherited());
    }

    public function test_the_page_scope_is_handed_to_the_script_as_its_url_parameters(): void
    {
        $block = $this->blockForElement(new DataObject(['scope' => 'websites']), $this->websiteScope());

        self::assertSame(['website' => '2'], json_decode($block->getScopeParamsJson(), true));
    }

    private function websiteScope(): ConfigScopeResolver
    {
        return new FixedConfigScopeResolver(new ConfigScope('websites', 2, 'second'));
    }

    private function blockForElement(DataObject $element, ?ConfigScopeResolver $scopeResolver = null): Services
    {
        $block = $this->block($scopeResolver ?? FixedConfigScopeResolver::atDefault());
        $block->setData('element', $element);

        return $block;
    }

    /**
     * The constructor pulls in the whole block context, and none of it is reached by the state this
     * test is about.
     */
    private function block(ConfigScopeResolver $scopeResolver): Services
    {
        $reflection = new \ReflectionClass(Services::class);
        $block = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('settingChecker')->setValue($block, $this->settingChecker);
        $reflection->getProperty('scopeResolver')->setValue($block, $scopeResolver);

        return $block;
    }
}

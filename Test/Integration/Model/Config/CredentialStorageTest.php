<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Config;

use Magento\Config\Model\Config as ConfigModel;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\Config as AppConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Api\AiServiceSelectorInterface;
use MageOS\AiBase\Model\Config\Backend\EncryptedServices;
use MageOS\AiBase\Model\Config\SensitiveDataProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Credentials from the admin form to the database and back, through the real save path.
 *
 * The unit test of this behaviour hands the backend model a fake encryptor whose ciphertext is
 * `enc(<plaintext>)`, which cannot be wrong about the one thing that matters: the value that
 * actually lands in `core_config_data`. It also cannot see the backend model failing to be wired
 * to the field at all, since it constructs the model itself. Saving the way the admin does exposes
 * both.
 *
 * The area is not decoration: `system.xml` is read into the config structure for adminhtml only, so
 * anywhere else the field has no backend model, Magento falls back to the plain config Value, and
 * the row array reaches the database unserialized. Saving as the admin does means being where it is.
 */
#[AppArea('adminhtml')]
final class CredentialStorageTest extends TestCase
{
    private const CONFIG_PATH = 'mageos_ai/services/configuration';
    private const API_KEY = 'sk-integration-secret';

    /**
     * A service code no module registers, standing in for a provider whose module was removed.
     */
    private const REMOVED_PROVIDER = 'acme_removed';

    private ObjectManagerInterface $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
    }

    protected function tearDown(): void
    {
        $this->objectManager->get(WriterInterface::class)->delete(self::CONFIG_PATH);
        $this->objectManager->get(WriterInterface::class)->delete(
            self::CONFIG_PATH,
            ScopeInterface::SCOPE_WEBSITES,
            $this->defaultWebsiteId()
        );
        $this->objectManager->get(AppConfig::class)->clean();
    }

    public function test_a_saved_credential_is_encrypted_at_rest_and_read_back_in_the_clear(): void
    {
        $this->saveServices([
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);

        self::assertStringNotContainsString(
            self::API_KEY,
            $this->storedValue(),
            'The API key is in core_config_data in plaintext.'
        );

        $services = $this->objectManager->get(AiServiceSelectorInterface::class)->getAll();
        self::assertCount(1, $services);
        self::assertSame(self::API_KEY, $services[0]->getConfiguration()['api_key']);
        self::assertSame('gpt-4o', $services[0]->getConfiguration()['model']);
    }

    /**
     * The form shows a credential as `******`, so an administrator changing the model of a saved
     * row posts that placeholder back for a key they never saw. Storing it verbatim would replace
     * every credential on the page with six asterisks the next time anyone saves anything.
     */
    public function test_saving_the_masked_placeholder_keeps_the_stored_credential(): void
    {
        $this->saveServices([
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);

        $this->saveServices([
            '_row1' => [
                'openai' => [
                    'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ]);

        $configuration = $this->objectManager->get(AiServiceSelectorInterface::class)->getAll()[0]
            ->getConfiguration();

        self::assertSame(self::API_KEY, $configuration['api_key'], 'The stored credential was lost.');
        self::assertSame('gpt-4o-mini', $configuration['model'], 'The edit that came with it was lost.');
    }

    /**
     * The form must never render a credential, decrypted or not: an administrator opening the page
     * would put every configured provider's key in their browser, and anyone who can read the page
     * source, a screen share or a support screenshot has them too.
     *
     * The backend model comes from the config structure rather than being constructed here, so this
     * also asserts that the field is wired to the class that does the masking.
     */
    public function test_the_admin_form_is_given_a_placeholder_instead_of_the_credential(): void
    {
        $this->saveServices([
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);

        $field = $this->objectManager->get(Structure::class)->getElement(self::CONFIG_PATH);
        self::assertInstanceOf(Field::class, $field);

        $backendModel = $field->getBackendModel();
        self::assertInstanceOf(EncryptedServices::class, $backendModel);

        $backendModel->setPath(self::CONFIG_PATH);
        $backendModel->setValue($this->storedValue());
        $backendModel->afterLoad();

        $loaded = $backendModel->getValue();
        self::assertIsArray($loaded, 'The form is handed the rows already decoded.');
        self::assertSame(
            SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
            $loaded['_row1']['openai']['api_key'],
            'The admin form was handed the credential itself.'
        );
        self::assertSame('gpt-4o', $loaded['_row1']['openai']['model'], 'Non-credential fields must still render.');
    }

    /**
     * A placeholder with nothing behind it is not a credential. Storing the asterisks verbatim
     * would send them to the provider as an API key and report the resulting authentication
     * failure as the provider's problem.
     */
    public function test_a_placeholder_with_no_stored_credential_behind_it_is_not_stored(): void
    {
        $this->saveServices([
            '_new_row' => [
                'openai' => [
                    'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                    'model' => 'gpt-4o',
                ],
            ],
        ]);

        $configuration = $this->objectManager->get(AiServiceSelectorInterface::class)->getAll()[0]
            ->getConfiguration();

        self::assertSame('', $configuration['api_key']);
    }

    /**
     * Row ids are the identity another module stores when an administrator picks a service, so a
     * save that leaves them alone is the difference between a stored selection surviving an
     * unrelated edit and silently pointing at another provider's account.
     */
    public function test_row_ids_survive_a_save(): void
    {
        $this->saveServices([
            '_first' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
            '_second' => ['anthropic' => ['api_key' => 'sk-ant-secret', 'model' => 'claude-sonnet-4-6']],
        ]);

        $ids = array_map(
            fn ($service): string => $service->getId(),
            $this->objectManager->get(AiServiceSelectorInterface::class)->getAll(),
        );

        self::assertSame(['_first', '_second'], $ids);
    }

    /**
     * A complete form post carries both markers; neither may end up stored as if it were a row.
     */
    public function test_a_fully_rendered_form_post_is_saved_without_its_markers(): void
    {
        $this->saveServices([
            EncryptedServices::EMPTY_MARKER => '',
            EncryptedServices::RENDERED_MARKER => '1',
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);

        $stored = json_decode($this->storedValue(), true);
        self::assertSame(['_row1'], array_keys($stored));
    }

    /**
     * The form's script failed before it rendered the stored rows, so the post carries only the
     * server-rendered empty marker. Saving that used to delete every service and credential.
     */
    public function test_a_form_post_from_a_form_that_did_not_finish_rendering_keeps_what_is_stored(): void
    {
        $this->saveServices([
            '_row1' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);
        $before = $this->storedValue();

        try {
            $this->saveServices([EncryptedServices::EMPTY_MARKER => '']);
            self::fail('A post from a form that did not finish rendering was accepted.');
        } catch (ValidatorException) {
        }

        $this->objectManager->get(AppConfig::class)->clean();
        self::assertSame($before, $this->storedValue());
    }

    /**
     * Leaving a key masked while pointing its row at another host would restore the stored key and
     * hand it to that host on the next Test Connection. The registered provider's descriptor is
     * what marks `base_url` as the field to watch, so this also asserts the flag reaches the guard
     * through the real registry.
     */
    public function test_a_masked_credential_is_not_carried_over_to_a_new_endpoint(): void
    {
        $this->saveServices([
            '_row1' => [
                'openai_compatible' => [
                    'api_key' => self::API_KEY,
                    'base_url' => 'https://gateway.internal',
                    'model' => 'llama3',
                ],
            ],
        ]);
        $before = $this->storedValue();

        try {
            $this->saveServices([
                '_row1' => [
                    'openai_compatible' => [
                        'api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER,
                        'base_url' => 'http://attacker.test',
                        'model' => 'llama3',
                    ],
                ],
            ]);
            self::fail('A masked credential was carried over to a new endpoint.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('has to be entered again', $exception->getMessage());
        }

        $this->objectManager->get(AppConfig::class)->clean();
        self::assertSame($before, $this->storedValue());
    }

    /**
     * A row whose provider module was removed has no schema, so the form cannot post it. An
     * unrelated Save Config used to treat that as a deletion and drop the row with its encrypted
     * credential (GitHub issue #65); it must now be carried over exactly as stored.
     */
    public function test_an_unrelated_save_keeps_a_row_whose_provider_is_not_registered(): void
    {
        $removedRow = $this->seedOpenAiAndRemovedProviderRows();

        $this->saveServices([
            EncryptedServices::EMPTY_MARKER => '',
            EncryptedServices::RENDERED_MARKER => '1',
            '_openai' => ['openai' => ['api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER, 'model' => 'gpt-4o']],
        ]);

        $stored = json_decode($this->storedValue(), true);
        self::assertSame(['_openai', '_acme'], array_keys($stored));
        self::assertSame($removedRow, json_encode($stored['_acme']), 'The kept row changed on the way through.');
    }

    /**
     * Deleting every row the form shows posts only the markers; the row the form could not offer
     * for editing stays.
     */
    public function test_removing_every_visible_row_keeps_a_row_whose_provider_is_not_registered(): void
    {
        $this->seedOpenAiAndRemovedProviderRows();

        $this->saveServices([EncryptedServices::EMPTY_MARKER => '', EncryptedServices::RENDERED_MARKER => '1']);

        self::assertSame(['_acme'], array_keys(json_decode($this->storedValue(), true)));
    }

    /**
     * The placeholder's delete button posts the row id under the deletion marker, which is the
     * deliberate way such a row is removed.
     */
    public function test_the_deletion_marker_removes_a_row_whose_provider_is_not_registered(): void
    {
        $this->seedOpenAiAndRemovedProviderRows();

        $this->saveServices([
            EncryptedServices::EMPTY_MARKER => '',
            EncryptedServices::RENDERED_MARKER => '1',
            EncryptedServices::DELETED_MARKER => ['_acme'],
            '_openai' => ['openai' => ['api_key' => SensitiveDataProcessor::OBSCURED_PLACEHOLDER, 'model' => 'gpt-4o']],
        ]);

        self::assertSame(['_openai'], array_keys(json_decode($this->storedValue(), true)));
    }

    /**
     * With "Use Default" ticked on a website, Magento saves nothing for the field there, so nothing
     * runs that could keep or drop a row, and the default scope's value is left alone.
     */
    public function test_a_website_that_inherits_the_services_changes_nothing(): void
    {
        $this->seedOpenAiAndRemovedProviderRows();
        $before = $this->storedValue();

        $config = $this->objectManager->create(ConfigModel::class);
        $config->setSection('mageos_ai');
        $config->setWebsite((string) $this->defaultWebsiteId());
        $config->setGroups(['services' => ['fields' => ['configuration' => [
            'inherit' => '1',
            'value' => [EncryptedServices::EMPTY_MARKER => '', EncryptedServices::RENDERED_MARKER => '1'],
        ]]]]);
        $config->save();
        $this->objectManager->get(AppConfig::class)->clean();

        self::assertSame($before, $this->storedValue());
        self::assertSame(
            $before,
            (string) $this->objectManager->get(ScopeConfigInterface::class)->getValue(
                self::CONFIG_PATH,
                ScopeInterface::SCOPE_WEBSITES,
                $this->defaultWebsiteId()
            ),
            'The website stopped inheriting the default services.'
        );
    }

    /**
     * Store an OpenAI row and a row for a provider that is not registered, the state an install is
     * in after that provider's module was removed. Written to the database directly, since the
     * save path would not accept a row for a provider it does not know of any more than the form.
     *
     * @return string The removed provider's row exactly as stored, JSON encoded
     */
    private function seedOpenAiAndRemovedProviderRows(): string
    {
        $this->saveServices([
            '_openai' => ['openai' => ['api_key' => self::API_KEY, 'model' => 'gpt-4o']],
        ]);
        $stored = json_decode($this->storedValue(), true);
        $stored['_acme'] = [
            self::REMOVED_PROVIDER => [
                'api_key' => $this->objectManager->get(EncryptorInterface::class)->encrypt('sk-acme-secret'),
                '_label' => 'Old gateway',
            ],
        ];
        $this->objectManager->get(WriterInterface::class)->save(self::CONFIG_PATH, json_encode($stored));
        $this->objectManager->get(AppConfig::class)->clean();

        return json_encode($stored['_acme']);
    }

    /**
     * @return int
     */
    private function defaultWebsiteId(): int
    {
        return (int) $this->objectManager->get(StoreManagerInterface::class)
            ->getDefaultStoreView()
            ?->getWebsiteId();
    }

    /**
     * Save a services configuration the way the admin form posts it.
     *
     * @param array<string, string|array<array-key, mixed>> $rows
     * @return void
     */
    private function saveServices(array $rows): void
    {
        $config = $this->objectManager->create(ConfigModel::class);
        $config->setSection('mageos_ai');
        $config->setGroups(['services' => ['fields' => ['configuration' => ['value' => $rows]]]]);
        $config->save();

        $this->objectManager->get(AppConfig::class)->clean();
    }

    /**
     * @return string
     */
    private function storedValue(): string
    {
        return (string) $this->objectManager->get(ScopeConfigInterface::class)->getValue(self::CONFIG_PATH);
    }
}

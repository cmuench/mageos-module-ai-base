<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\ValidatorException;
use MageOS\AiBase\Model\Config\Backend\EncryptedServices;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The admin form's rows are built by JavaScript, while its `__empty` input is plain markup. A script
 * that never ran, or stopped halfway, posted `__empty` with none or only some of the rows, and the
 * save stored exactly that: every provider and credential after the failure was deleted. The form
 * now adds a marker as its last step, and a form post without it is refused.
 *
 * Only the refusal is covered here, because it happens before the model touches anything its
 * constructor would provide. An accepted save goes through the real save path in
 * `Test/Integration/Model/Config/CredentialStorageTest`.
 *
 * @covers \MageOS\AiBase\Model\Config\Backend\EncryptedServices
 */
final class EncryptedServicesTest extends TestCase
{
    /**
     * @param array<string, mixed> $posted
     */
    #[DataProvider('form_posts_from_a_form_that_did_not_finish_rendering')]
    public function test_a_form_post_without_the_rendered_marker_is_refused(array $posted): void
    {
        $model = $this->model();
        $model->setValue($posted);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('the form did not finish loading');

        $model->beforeSave();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function form_posts_from_a_form_that_did_not_finish_rendering(): array
    {
        return [
            'script never ran, no rows' => [['__empty' => '']],
            'script stopped after the first row' => [[
                '__empty' => '',
                '_first' => ['openai' => ['api_key' => '******', 'model' => 'gpt-4o']],
            ]],
        ];
    }

    private function model(): EncryptedServices
    {
        return (new \ReflectionClass(EncryptedServices::class))->newInstanceWithoutConstructor();
    }
}

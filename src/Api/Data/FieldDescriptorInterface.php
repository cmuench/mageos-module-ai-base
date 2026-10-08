<?php

declare(strict_types=1);

namespace MageOS\AiBase\Api\Data;

/**
 * Stable to call, not meant to be implemented: methods may be added in a minor release. To change
 * what it does, write a plugin on it instead of replacing it.
 *
 * @api
 */
interface FieldDescriptorInterface
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_SELECT   = 'select';

    /**
     * Field name used as the input name suffix.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Human-readable field label.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Input type; one of the TYPE_* constants.
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Options for select fields.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getOptions(): array;

    /**
     * Default value pre-filled in the input, if any.
     *
     * @return string|null
     */
    public function getDefault(): ?string;

    /**
     * Whether the field holds a credential that must be encrypted at rest and masked in the admin form.
     *
     * @return bool
     */
    public function isEncrypted(): bool;
}

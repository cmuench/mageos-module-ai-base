<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Config;

use MageOS\AiBase\Model\ServiceRegistry;

/**
 * Carries stored rows of providers that are no longer registered over into a save that did not post them.
 *
 * A save of the services field replaces the whole stored value with the rows it was given, so a
 * row the post leaves out is a row the administrator deleted. That only holds for rows the
 * administrator could edit. A row whose provider module was removed or disabled has no field
 * schema, so the form has nothing to build inputs from and posts nothing for it. Treating that as
 * a deletion threw away the row and its encrypted credentials on the next unrelated Save Config,
 * and with them the row id other modules store as their selection, which reinstalling the
 * provider could not bring back.
 *
 * This is the server-side half of the fix and does not depend on the form at all: whatever posted
 * the save, such a row stays exactly as stored (still encrypted) unless its id is in the explicit
 * deletion list the form's delete button posts. Rows of registered providers are left to the
 * post, so leaving one out still deletes it.
 */
class UnregisteredRowKeeper
{
    /**
     * @param ServiceRegistry $serviceRegistry Decides which stored rows the form could not render
     */
    public function __construct(
        private readonly ServiceRegistry $serviceRegistry,
    ) {
    }

    /**
     * The posted rows plus every stored unregistered row the post did not mention.
     *
     * A posted row with the same id wins: the post is the more deliberate statement about that id.
     * Kept rows are appended after the posted ones rather than slotted back into their old position;
     * the form lists rows in stored order, so they simply move to the end on the first save, and
     * nothing reads meaning into the order of rows.
     *
     * @param array<array-key,mixed> $posted Rows as they are about to be stored
     * @param array<array-key,mixed> $stored Rows currently stored at the scope being saved, still encrypted
     * @param list<string> $deletedRowIds Rows the administrator removed on purpose with their delete button
     * @return array<array-key,mixed>
     */
    public function keep(array $posted, array $stored, array $deletedRowIds): array
    {
        return $posted + array_filter(
            $stored,
            fn (mixed $row, int|string $rowId): bool => !in_array((string) $rowId, $deletedRowIds, true)
                && $this->isUnregisteredRow($row),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Whether a stored row belongs to a provider that is not registered on this install.
     *
     * Uses the same notion of a row the admin form renders from (a string service code holding an
     * array of fields), so every row kept here is one the form shows a placeholder for, and can
     * therefore also be deleted from there. A malformed row the form cannot show is not kept, the
     * same as before: keeping it would make it impossible to remove without editing the database.
     *
     * @param mixed $row
     * @return bool
     */
    private function isUnregisteredRow(mixed $row): bool
    {
        if (!is_array($row)) {
            return false;
        }
        $code = array_key_first($row);

        return is_string($code)
            && is_array($row[$code])
            && $this->serviceRegistry->get($code) === null;
    }
}

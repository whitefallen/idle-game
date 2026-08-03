<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

/**
 * The transaction boundary, owned by the Application layer.
 *
 * Handlers decide what must commit together; they should not have to know that
 * persistence happens to be Doctrine. Expressing the boundary through this
 * interface keeps the Application layer free of the ORM and makes the intent
 * ("these changes commit as one") explicit rather than implied by a flush call.
 */
interface TransactionManager
{
    /**
     * Runs $work inside a database transaction and returns its result.
     *
     * Used where a read and the write that depends on it must be atomic — a
     * locked row read followed by an update, for instance.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     *
     * @throws DuplicateKeyException when a unique constraint is violated
     */
    public function transactional(callable $work): mixed;

    /**
     * Persists everything staged so far.
     *
     * @throws DuplicateKeyException when a unique constraint is violated
     */
    public function commit(): void;
}

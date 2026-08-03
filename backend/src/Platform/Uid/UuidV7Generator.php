<?php

declare(strict_types=1);

namespace App\Platform\Uid;

use Symfony\Component\Uid\Uuid;

/**
 * Time-ordered UUIDv7. See ADR-0005.
 *
 * Version 7 rather than 4 because random primary keys insert at uniformly
 * distributed positions in the B-tree, so every insert dirties a different page
 * and write throughput degrades as the table grows — a problem that only
 * appears months into production, when migrating the primary key of the largest
 * tables is at its most expensive.
 *
 * Because a v7 identifier embeds its creation time, it must never be used where
 * an identifier has to be opaque: password reset tokens, session identifiers and
 * invite codes use cryptographically random values instead.
 */
final class UuidV7Generator implements IdentifierGenerator
{
    public function generate(): Uuid
    {
        return Uuid::v7();
    }
}

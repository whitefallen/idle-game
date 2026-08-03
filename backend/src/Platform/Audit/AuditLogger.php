<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use Symfony\Component\Uid\Uuid;

/**
 * Records audited events.
 *
 * Two methods, because audit records fall into two categories with opposite
 * requirements:
 *
 *  - {@see record()} stages the entry so it commits with the state change it
 *    describes. A reward that rolled back must not leave a record claiming it
 *    was granted.
 *
 *  - {@see recordNow()} writes immediately. Security events have no transaction
 *    to join, and a failed authentication must be recorded precisely when the
 *    surrounding request is about to fail.
 *
 * Getting these the wrong way round is the difference between an audit trail
 * that is evidence and one that is a rumour.
 */
interface AuditLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function record(
        AuditAction $action,
        array $context = [],
        ?Uuid $accountId = null,
        ?Uuid $characterId = null,
    ): void;

    /**
     * @param array<string, mixed> $context
     */
    public function recordNow(
        AuditAction $action,
        array $context = [],
        ?Uuid $accountId = null,
        ?Uuid $characterId = null,
    ): void;
}

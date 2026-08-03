<?php

declare(strict_types=1);

namespace App\Platform\Retention;

/**
 * How long a growth table's rows are kept.
 *
 * Declared in one place so the policy is reviewable as a policy, rather than
 * being scattered across whichever command happens to delete each table.
 */
final readonly class RetentionPolicy
{
    /**
     * @param string $table       Table to prune.
     * @param string $column      Timestamp column the cutoff applies to. Must be
     *                            indexed, or the delete degrades into a scan.
     * @param int    $retainDays  Rows older than this are removed.
     * @param string $rationale   Why this figure, for whoever changes it later.
     * @param string $keyColumn   Primary key, used to select each batch. Not
     *                            every table calls it `id`.
     */
    public function __construct(
        public string $table,
        public string $column,
        public int $retainDays,
        public string $rationale,
        public string $keyColumn = 'id',
    ) {
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self(
                table: 'encounter',
                column: 'created_at',
                retainDays: 90,
                rationale: 'Full combat logs are large and are almost never read after the session that produced them.',
            ),
            new self(
                table: 'audit_log',
                column: 'occurred_at',
                retainDays: 400,
                rationale: 'A full year plus investigation lag, so a dispute raised late still has evidence.',
            ),
            new self(
                table: 'outbox',
                column: 'published_at',
                retainDays: 7,
                rationale: 'Published messages are kept only long enough to debug a delivery problem.',
            ),
            new self(
                table: 'idempotency_record',
                column: 'created_at',
                retainDays: 1,
                rationale: 'Matches the replay window advertised in docs/api.md section 4.',
                keyColumn: 'idempotency_key',
            ),
        ];
    }
}

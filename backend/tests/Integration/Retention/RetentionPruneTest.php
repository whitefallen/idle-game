<?php

declare(strict_types=1);

namespace App\Tests\Integration\Retention;

use App\Platform\Retention\RetentionPolicy;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Retention pruning.
 *
 * This is what replaced range partitioning. Dropping a partition would be
 * cheaper, but it makes the scheduled job load-bearing — miss a
 * partition-creation run and inserts fail. Missing this job costs disk and
 * nothing else, which is the right way round at this scale.
 *
 * The tests exist because a prune that silently deletes nothing looks exactly
 * like a prune with nothing to do.
 */
final class RetentionPruneTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $this->connection = $connection;

        $this->connection->executeStatement('TRUNCATE TABLE audit_log');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function prune(array $arguments = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel, 'The kernel must be booted before running a command.');

        $tester = new CommandTester(
            (new Application($kernel))->find('db:retention:prune'),
        );

        $tester->execute(['--table' => 'audit_log', ...$arguments]);

        return $tester;
    }

    private function insertAuditRows(int $count, int $daysAgo): void
    {
        $occurredAt = (new DateTimeImmutable())->modify(sprintf('-%d days', $daysAgo));

        for ($i = 0; $i < $count; ++$i) {
            $this->connection->executeStatement(
                'INSERT INTO audit_log (id, action, account_id, character_id, context, ip_hash, occurred_at)
                 VALUES (?, ?, NULL, NULL, ?, NULL, ?)',
                [
                    Uuid::v7()->toRfc4122(),
                    'auth.failed',
                    '{}',
                    $occurredAt->format('Y-m-d H:i:sP'),
                ],
            );
        }
    }

    private function remaining(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM audit_log');
    }

    private function auditRetentionDays(): int
    {
        foreach (RetentionPolicy::all() as $policy) {
            if ($policy->table === 'audit_log') {
                return $policy->retainDays;
            }
        }

        self::fail('No retention policy declared for audit_log.');
    }

    public function testDeletesRowsPastTheRetentionWindow(): void
    {
        $retention = $this->auditRetentionDays();

        $this->insertAuditRows(20, $retention + 10);
        $this->insertAuditRows(5, 1);

        self::assertSame(25, $this->remaining());

        $this->prune();

        // Only the rows past the window go; recent ones are untouched.
        self::assertSame(5, $this->remaining());
    }

    public function testRowsInsideTheWindowAreNeverDeleted(): void
    {
        $this->insertAuditRows(10, $this->auditRetentionDays() - 1);

        $this->prune();

        self::assertSame(10, $this->remaining());
    }

    public function testDryRunReportsWithoutDeleting(): void
    {
        $this->insertAuditRows(12, $this->auditRetentionDays() + 5);

        $tester = $this->prune(['--dry-run' => true]);

        self::assertSame(12, $this->remaining(), 'A dry run must not delete.');
        self::assertStringContainsString('12', $tester->getDisplay(), 'It must still report what is eligible.');
    }

    /**
     * Deleting in batches is what keeps this off a hot table for minutes at a
     * time. Several batches must add up to the same result as one large one.
     */
    public function testBatchingDeletesEverythingEligible(): void
    {
        $this->insertAuditRows(47, $this->auditRetentionDays() + 3);

        $this->prune(['--batch' => 10]);

        self::assertSame(0, $this->remaining());
    }

    /**
     * The batch cap bounds a single run so an unattended job cannot overrun its
     * window. Reaching it leaves work behind on purpose, and says so.
     */
    public function testBatchCapLimitsASingleRunAndIsReported(): void
    {
        $this->insertAuditRows(40, $this->auditRetentionDays() + 3);

        $tester = $this->prune(['--batch' => 5, '--max-batches' => 2]);

        // Two batches of five, so thirty rows should survive this run.
        self::assertSame(30, $this->remaining());
        self::assertStringContainsString('Batch cap reached', $tester->getDisplay());

        // The next run continues from where it stopped, which is the property
        // that makes interrupting safe.
        $this->prune(['--batch' => 100]);

        self::assertSame(0, $this->remaining());
    }

    public function testPruningIsSafeWhenThereIsNothingToDelete(): void
    {
        $tester = $this->prune();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, $this->remaining());
    }

    /**
     * Every policy must name a column that exists, or the prune fails at
     * runtime on a table nobody is watching.
     */
    public function testEveryPolicyTargetsRealColumns(): void
    {
        foreach (RetentionPolicy::all() as $policy) {
            $columns = array_map(
                static fn (array $column): string => (string) $column['column_name'],
                $this->connection->fetchAllAssociative(
                    'SELECT column_name FROM information_schema.columns WHERE table_name = ?',
                    [$policy->table],
                ),
            );

            self::assertNotEmpty($columns, sprintf('Table "%s" does not exist.', $policy->table));
            self::assertContains($policy->column, $columns, sprintf('%s.%s', $policy->table, $policy->column));
            self::assertContains($policy->keyColumn, $columns, sprintf('%s.%s', $policy->table, $policy->keyColumn));
        }
    }
}

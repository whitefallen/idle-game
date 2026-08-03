<?php

declare(strict_types=1);

namespace App\Platform\Retention\Console;

use App\Platform\Clock\Clock;
use App\Platform\Retention\RetentionPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes rows past their retention window, in batches.
 *
 * This is the alternative to range partitioning, chosen deliberately at this
 * scale. Dropping a partition is cheaper than deleting rows, but partitioning
 * makes the scheduled job load-bearing: miss a partition-creation run and
 * inserts start failing, which for the encounter table means players cannot
 * fight. Miss this job and the only consequence is disk. Trading an outage risk
 * for a disk-usage risk is the right way round until the numbers say otherwise.
 * See docs/data-model.md section 6.
 *
 * Batched rather than a single statement so it can be interrupted safely, holds
 * no long transaction, and does not lock a hot table for minutes at a time. Each
 * batch commits on its own, so stopping halfway simply leaves work for the next
 * run.
 */
#[AsCommand(
    name: 'db:retention:prune',
    description: 'Delete rows past their retention window, in batches',
)]
final class PruneRetentionCommand extends Command
{
    private const int DEFAULT_BATCH_SIZE = 5_000;

    /** Caps a single run so an unattended job cannot run past its window. */
    private const int DEFAULT_MAX_BATCHES = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per batch', (string) self::DEFAULT_BATCH_SIZE)
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Maximum batches per table', (string) self::DEFAULT_MAX_BATCHES)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Prune only this table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize = max(1, (int) $input->getOption('batch'));
        $maxBatches = max(1, (int) $input->getOption('max-batches'));
        $dryRun = (bool) $input->getOption('dry-run');
        $only = $input->getOption('table');

        $io->title($dryRun ? 'Retention prune (dry run)' : 'Retention prune');

        $rows = [];
        $incomplete = [];

        foreach (RetentionPolicy::all() as $policy) {
            if (is_string($only) && $only !== $policy->table) {
                continue;
            }

            $cutoff = $this->clock->now()->modify(sprintf('-%d days', $policy->retainDays));

            $eligible = (int) $this->connection->fetchOne(
                sprintf('SELECT count(*) FROM %s WHERE %s < ?', $policy->table, $policy->column),
                [$cutoff->format('Y-m-d H:i:sP')],
            );

            $deleted = 0;

            if (!$dryRun && $eligible > 0) {
                [$deleted, $exhausted] = $this->prune($policy, $cutoff->format('Y-m-d H:i:sP'), $batchSize, $maxBatches);

                if (!$exhausted) {
                    $incomplete[] = $policy->table;
                }
            }

            $rows[] = [
                $policy->table,
                $policy->retainDays . 'd',
                $cutoff->format('Y-m-d'),
                $eligible,
                $dryRun ? '—' : $deleted,
            ];
        }

        $io->table(['table', 'retain', 'cutoff', 'eligible', 'deleted'], $rows);

        if ($incomplete !== []) {
            // Not an error: the cap did its job. Reported so a table that never
            // catches up is visible rather than quietly growing forever.
            $io->warning(sprintf(
                'Batch cap reached for: %s. Rows remain; the next run continues.',
                implode(', ', $incomplete),
            ));
        }

        $io->success('Retention prune complete.');

        return Command::SUCCESS;
    }

    /**
     * @return array{int, bool} Rows deleted, and whether everything eligible was removed.
     */
    private function prune(RetentionPolicy $policy, string $cutoff, int $batchSize, int $maxBatches): array
    {
        $deleted = 0;

        // Deletes by primary key chosen in a subquery. A bare
        // "DELETE ... WHERE column < cutoff LIMIT n" is not valid in Postgres,
        // and this form lets the planner use the index on the timestamp column
        // to find the batch rather than scanning.
        $sql = sprintf(
            'DELETE FROM %1$s WHERE %4$s IN (SELECT %4$s FROM %1$s WHERE %2$s < ? ORDER BY %2$s LIMIT %3$d)',
            $policy->table,
            $policy->column,
            $batchSize,
            $policy->keyColumn,
        );

        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $removed = (int) $this->connection->executeStatement($sql, [$cutoff]);
            $deleted += $removed;

            if ($removed < $batchSize) {
                return [$deleted, true];
            }
        }

        return [$deleted, false];
    }
}

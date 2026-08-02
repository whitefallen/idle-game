<?php

declare(strict_types=1);

namespace App\Platform\Content\Console;

use App\Platform\Content\ContentIssue;
use App\Platform\Content\ContentProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Validates the whole content library.
 *
 * Runs in CI on every content change, so malformed content fails the build
 * instead of reaching runtime. Every provider is checked even when an earlier
 * one fails, so an author learns about all their mistakes at once.
 */
#[AsCommand(
    name: 'content:validate',
    description: 'Validate the content library against its schemas and check referential integrity',
)]
final class ValidateContentCommand extends Command
{
    /**
     * @param iterable<ContentProvider> $providers
     */
    public function __construct(
        #[AutowireIterator('app.content_provider')]
        private readonly iterable $providers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Content validation');

        $totalIssues = 0;
        $checked = 0;

        foreach ($this->providers as $provider) {
            ++$checked;

            try {
                $issues = $provider->validateContent();
            } catch (\Throwable $e) {
                // A provider that throws is itself a content failure; report it
                // and keep going so one broken file does not mask the rest.
                $issues = [new ContentIssue($provider->contentName(), '', $e->getMessage())];
            }

            if ($issues === []) {
                $io->writeln(sprintf('  <fg=green>OK</>       %s', $provider->contentName()));

                continue;
            }

            $totalIssues += count($issues);
            $io->writeln(sprintf('  <fg=red>FAILED</>   %s', $provider->contentName()));

            foreach ($issues as $issue) {
                $io->writeln(sprintf('             %s', $issue));
            }
        }

        $io->newLine();

        if ($checked === 0) {
            $io->error('No content providers are registered.');

            return Command::FAILURE;
        }

        if ($totalIssues > 0) {
            $io->error(sprintf('%d issue(s) across %d content type(s).', $totalIssues, $checked));

            return Command::FAILURE;
        }

        $io->success(sprintf('All %d content type(s) valid.', $checked));

        return Command::SUCCESS;
    }
}

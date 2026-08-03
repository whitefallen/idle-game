<?php

declare(strict_types=1);

namespace App\Platform\Outbox\Console;

use App\Platform\Clock\Clock;
use App\Platform\Outbox\DomainEventNotification;
use App\Platform\Outbox\OutboxMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publishes staged domain events onto the message bus.
 *
 * The relay is what turns the outbox table into delivery. It is deliberately a
 * separate process: publication must not sit in the request path, and a slow or
 * failing subscriber must not be able to roll back the fight that produced the
 * event.
 *
 * Delivery is at-least-once — a crash between dispatch and the published_at
 * write replays the message — so every subscriber must be idempotent.
 * See ADR-0004.
 */
#[AsCommand(
    name: 'outbox:relay',
    description: 'Publish unpublished domain events onto the message bus',
)]
final class RelayOutboxCommand extends Command
{
    private const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'batch',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum messages to publish in one run',
            (string) self::DEFAULT_BATCH_SIZE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = max(1, (int) $input->getOption('batch'));

        /** @var list<OutboxMessage> $pending */
        $pending = $this->entityManager
            ->getRepository(OutboxMessage::class)
            ->findBy(['publishedAt' => null], ['occurredAt' => 'ASC'], $batch);

        if ($pending === []) {
            $io->writeln('Nothing to publish.');

            return Command::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        foreach ($pending as $message) {
            $message->recordAttempt();

            try {
                $this->bus->dispatch(new DomainEventNotification(
                    $message->eventType(),
                    $message->payload(),
                    $message->occurredAt(),
                ));

                $message->markPublished($this->clock->now());
                ++$published;
            } catch (\Throwable $e) {
                // One poisoned message must not stop the queue. The attempt
                // counter makes a repeatedly failing message visible instead of
                // letting it retry silently forever.
                ++$failed;
                $io->warning(sprintf(
                    'Failed to publish %s (%s), attempt %d: %s',
                    $message->eventType(),
                    $message->id()->toRfc4122(),
                    $message->attempts(),
                    $e->getMessage(),
                ));
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Published %d message(s), %d failure(s).', $published, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

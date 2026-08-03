<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function transactional(callable $work): mixed
    {
        try {
            return $this->entityManager->wrapInTransaction($work);
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateKeyException(previous: $e);
        }
    }

    public function commit(): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateKeyException(previous: $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Feature\Account\Infrastructure\Doctrine;

use App\Feature\Account\Domain\Entity\Account;
use App\Feature\Account\Domain\Model\Email;
use App\Feature\Account\Domain\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineAccountRepository implements AccountRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findById(Uuid $id): ?Account
    {
        return $this->entityManager->find(Account::class, $id);
    }

    public function findByEmail(Email $email): ?Account
    {
        return $this->entityManager
            ->getRepository(Account::class)
            ->findOneBy(['email' => $email->value]);
    }

    public function existsByEmail(Email $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    /**
     * Persists without flushing. Flushing is the Application layer's decision,
     * because it owns the transaction boundary and knows which changes must
     * commit together.
     */
    public function save(Account $account): void
    {
        $this->entityManager->persist($account);
    }
}

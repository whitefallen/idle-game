<?php

declare(strict_types=1);

namespace App\Feature\Account\Domain\Repository;

use App\Feature\Account\Domain\Entity\Account;
use App\Feature\Account\Domain\Model\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Persists accounts. Repositories persist data only; no business rules live here.
 */
interface AccountRepository
{
    public function findById(Uuid $id): ?Account;

    public function findByEmail(Email $email): ?Account;

    public function existsByEmail(Email $email): bool;

    public function save(Account $account): void;
}

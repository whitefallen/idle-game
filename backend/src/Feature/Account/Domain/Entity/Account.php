<?php

declare(strict_types=1);

namespace App\Feature\Account\Domain\Entity;

use App\Feature\Account\Domain\Model\AccountStatus;
use App\Feature\Account\Domain\Model\Email;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A player account.
 *
 * Mapping attributes are declarative metadata, not behaviour: they describe how
 * this entity is stored but cannot perform persistence. The rule that matters —
 * no EntityManager, no repository and no query in the Domain — is enforced by
 * deptrac. See the DoctrineMapping layer in deptrac.yaml.
 *
 * The entity deliberately does not implement Symfony's UserInterface. That
 * adapter lives in Infrastructure, so the domain model stays free of the
 * security component.
 */
#[ORM\Entity]
#[ORM\Table(name: 'account')]
#[ORM\UniqueConstraint(name: 'uq_account_email', columns: ['email'])]
#[ORM\Index(name: 'idx_account_status', columns: ['status'])]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: Email::MAX_LENGTH)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: 'string', length: 20, enumType: AccountStatus::class)]
    private AccountStatus $status;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * The identifier is supplied rather than generated here, so that entity
     * construction stays deterministic and testable. Generation belongs to the
     * Application layer, which owns the identifier factory.
     */
    public function __construct(Uuid $id, Email $email, string $passwordHash, DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->email = $email->value;
        $this->passwordHash = $passwordHash;
        $this->status = AccountStatus::Active;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): Email
    {
        return Email::fromString($this->email);
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function changePassword(string $passwordHash, DateTimeImmutable $now): void
    {
        $this->passwordHash = $passwordHash;
        $this->updatedAt = $now;
    }

    public function suspend(DateTimeImmutable $now): void
    {
        $this->status = AccountStatus::Suspended;
        $this->updatedAt = $now;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

<?php

declare(strict_types=1);

namespace App\Feature\Account\Infrastructure\Security;

use App\Feature\Account\Domain\Entity\Account;
use App\Platform\Security\AuthenticatedAccount;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adapts an Account to the security component.
 *
 * The adapter exists so the domain entity does not implement a framework
 * interface. That keeps Account free of security concerns and means a change in
 * how Symfony models users cannot ripple into the domain.
 *
 * Only the identifier is carried through the session; the account is reloaded
 * on each request, so a suspension takes effect immediately rather than at the
 * next login.
 */
final class AccountUser implements UserInterface, PasswordAuthenticatedUserInterface, AuthenticatedAccount
{
    /**
     * @param non-empty-string $email The security component treats the user
     *                                identifier as non-empty; an Email value
     *                                object guarantees that upstream.
     */
    public function __construct(
        private readonly string $accountId,
        private readonly string $email,
        private readonly string $passwordHash,
    ) {
    }

    public static function fromAccount(Account $account): self
    {
        /** @var non-empty-string $email An Email value object is never empty. */
        $email = $account->email()->value;

        return new self(
            $account->id()->toRfc4122(),
            $email,
            $account->passwordHash(),
        );
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: the hash is needed for the lifetime of the request
        // and this object is never serialised into the session.
    }
}
